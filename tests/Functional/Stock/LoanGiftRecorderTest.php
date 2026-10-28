<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Repository\PlayerHoldingRepository;
use App\Repository\StockLevelRepository;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockReturn;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use App\Stock\StockQuery;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LoanGiftRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMovementRecorder $recorder;
    private Location $local;
    private Player $player1;
    private Player $player6;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->boot();
        $this->local = StockFixtures::location($this->em, 'Local matériel');
        $this->player1 = PlayerFixtures::player($this->em, 'Martin', 'Léo');
        $this->player6 = PlayerFixtures::player($this->em, 'Durand', 'Hugo');
    }

    private function boot(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $recorder = static::getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $this->recorder = $recorder;
    }

    private function helmet(): Variant
    {
        return StockScenario::variant($this->em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
    }

    private function receive(Variant $variant, int $quantity, int $cost, StockCondition $condition = StockCondition::New): void
    {
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->local, [new StockEntryLine($variant, $quantity, $cost, $condition)]));
    }

    /**
     * @return list<Item>
     */
    private function items(Variant $variant): array
    {
        /** @var list<Item> $items */
        $items = $this->em->getRepository(Item::class)->findBy(['variant' => $variant], ['code' => 'ASC']);

        return $items;
    }

    /**
     * Un refus dans la transaction ferme l'EntityManager (comme en requête réelle) : on repart d'un EM neuf
     * et on recharge les entités du scénario.
     */
    private function resetManager(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        \assert($doctrine instanceof ManagerRegistry);
        $doctrine->resetManager();
        $this->boot();
        $this->local = $this->reload(Location::class, $this->local->getId());
        $this->player1 = $this->reload(Player::class, $this->player1->getId());
        $this->player6 = $this->reload(Player::class, $this->player6->getId());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, ?int $id): object
    {
        $entity = $this->em->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    private function operations(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o')->getSingleScalarResult();
    }

    private function level(Variant $variant, StockCondition $condition, ?Location $location = null): ?StockLevel
    {
        return $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $variant->getId(), 'location' => ($location ?? $this->local)->getId(), 'condition' => $condition]);
    }

    private function holding(Player $player, Variant $variant): ?PlayerHolding
    {
        return $this->em->getRepository(PlayerHolding::class)->findOneBy(['player' => $player->getId(), 'variant' => $variant->getId()]);
    }

    private function totalValue(): int
    {
        $query = static::getContainer()->get(StockQuery::class);
        \assert($query instanceof StockQuery);

        return $query->totalValueHt(null);
    }

    public function testLoanMovesQuantityAndValueToHolding(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 3, 10000, StockCondition::Worn);

        $operation = $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::Worn, 2, 'Saison'));

        self::assertSame(StockMovementType::LoanOut, $operation->getType());
        self::assertSame($this->local->getId(), $operation->getSource()?->getId());
        self::assertNull($operation->getDestination());
        self::assertSame($this->player1->getId(), $operation->getPlayer()?->getId());
        self::assertNull($operation->getTeam());
        self::assertSame('Saison', $operation->getComment());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(2, $movement->getQuantity());
        self::assertSame(StockCondition::Worn, $movement->getCondition());
        self::assertSame(10000, $movement->getUnitCostHt());
        self::assertNull($movement->getItem());

        $this->em->clear();
        $level = $this->level($helmet, StockCondition::Worn);
        self::assertSame(1, $level?->getQuantity());
        self::assertSame(10000, $level->getValueHt());
        $holding = $this->holding($this->player1, $helmet);
        self::assertSame(2, $holding?->getQuantity());
        self::assertSame(20000, $holding->getValueHt());
        self::assertSame(30000, $this->totalValue());
    }

    public function testLoanRefusesConsumableAndShortStock(): void
    {
        $pucks = StockScenario::variant($this->em, ProductKind::Consumable, 'PALET-1', 'Palet');
        StockFixtures::level($this->em, $pucks, $this->local, 10, 100);
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 5, 15000);
        StockFixtures::level($this->em, $helmet, $this->local, 1, 15000, StockCondition::Worn);
        $before = $this->operations();

        $cases = [
            [$pucks, StockCondition::New, 1, '« PALET-1 M » est un consommable : il ne se prête pas.'],
            [$helmet, StockCondition::New, 0, 'La quantité doit être au moins 1.'],
            [$helmet, StockCondition::Worn, 2, 'Stock insuffisant pour « CASQUE-1 M » (Usé) à « Local matériel » : 1 disponible(s).'],
            [$helmet, StockCondition::Good, 1, 'Stock insuffisant pour « CASQUE-1 M » (Bon) à « Local matériel » : 0 disponible(s).'],
        ];
        foreach ($cases as [$variant, $condition, $quantity, $message]) {
            $this->resetManager();
            $variant = $this->reload(Variant::class, $variant->getId());
            try {
                $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $variant, $condition, $quantity));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $this->resetManager();
        self::assertSame($before, $this->operations());
        self::assertSame(1, $this->level($helmet, StockCondition::Worn)?->getQuantity());
        self::assertNull($this->holding($this->player1, $helmet));
    }

    public function testLoanFromInactiveLocationIsRefused(): void
    {
        $helmet = $this->helmet();
        $cave = StockFixtures::location($this->em, 'Cave', false);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le lieu « Cave » est inactif.');
        $this->recorder->recordLoan(new StockLoan($cave, $this->player1, $helmet, StockCondition::New, 1));
    }

    public function testSecondLoanAddsToHoldingAtLevelAverage(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 2, 10000);
        StockFixtures::level($this->em, $helmet, $this->local, 1, 4000, StockCondition::Good);

        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New, 1));
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::Good, 1));

        $this->em->clear();
        $holding = $this->holding($this->player1, $helmet);
        self::assertSame(2, $holding?->getQuantity());
        self::assertSame(14000, $holding->getValueHt());
        self::assertSame(1, (int) $this->em->createQuery('SELECT COUNT(h.id) FROM '.PlayerHolding::class.' h')->getSingleScalarResult());
    }

    public function testPartialReturnMovesValueAtHoldingAverage(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 2, 10000);
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New, 2));
        $before = $this->totalValue();
        self::assertSame(20000, $before);

        $operation = $this->recorder->recordReturn(new StockReturn($this->player1, $helmet, 1, $this->local, StockCondition::Damaged, 'Fin de saison'));

        self::assertSame(StockMovementType::LoanReturn, $operation->getType());
        self::assertNull($operation->getSource());
        self::assertSame($this->local->getId(), $operation->getDestination()?->getId());
        self::assertSame($this->player1->getId(), $operation->getPlayer()?->getId());
        self::assertSame('Fin de saison', $operation->getComment());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(1, $movement->getQuantity());
        self::assertSame(StockCondition::Damaged, $movement->getCondition());
        self::assertSame(10000, $movement->getUnitCostHt());

        $this->em->clear();
        $holding = $this->holding($this->player1, $helmet);
        self::assertSame(1, $holding?->getQuantity());
        self::assertSame(10000, $holding->getValueHt());
        $damaged = $this->level($helmet, StockCondition::Damaged);
        self::assertSame(1, $damaged?->getQuantity());
        self::assertSame(10000, $damaged->getValueHt());
        self::assertSame(0, $this->level($helmet, StockCondition::New)?->getQuantity());
        self::assertSame($before, $this->totalValue());
    }

    public function testReturnCannotExceedHeldQuantity(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 1, 10000);
        $reserve = StockFixtures::location($this->em, 'Cave', false);
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New, 1));
        $before = $this->operations();

        /** @var list<array{string, int, ?Location, string}> $cases */
        $cases = [
            ['player1', 2, null, 'Léo Martin détient 1 « CASQUE-1 M » : retour de 2 impossible.'],
            ['player6', 1, null, 'Hugo Durand détient 0 « CASQUE-1 M » : retour de 1 impossible.'],
            ['player1', 0, null, 'La quantité doit être au moins 1.'],
            ['player1', 1, $reserve, 'Le lieu « Cave » est inactif.'],
        ];
        foreach ($cases as [$who, $quantity, $location, $message]) {
            $this->resetManager();
            $helmet = $this->reload(Variant::class, $helmet->getId());
            $player = 'player1' === $who ? $this->player1 : $this->player6;
            $to = null === $location ? $this->local : $this->reload(Location::class, $location->getId());
            try {
                $this->recorder->recordReturn(new StockReturn($player, $helmet, $quantity, $to, StockCondition::Good));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $this->resetManager();
        self::assertSame($before, $this->operations());
        self::assertSame(1, $this->holding($this->player1, $helmet)?->getQuantity());
        self::assertNull($this->level($helmet, StockCondition::Good));
    }

    public function testHoldingDisappearsWhenFullyReturned(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 2, 10000);
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New, 2));

        $this->recorder->recordReturn(new StockReturn($this->player1, $helmet, 2, $this->local, StockCondition::Worn));

        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM player_holding'));
        $this->em->clear();
        $worn = $this->level($helmet, StockCondition::Worn);
        self::assertSame(2, $worn?->getQuantity());
        self::assertSame(20000, $worn->getValueHt());
    }

    public function testRepositoriesReadLevelsAndHoldings(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 3, 10000);
        StockFixtures::level($this->em, $helmet, $this->local, 1, 10000, StockCondition::Worn);
        StockFixtures::level($this->em, $helmet, $this->local, 1, 10000, StockCondition::Good);
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New, 2));
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player6, $helmet, StockCondition::Good, 1));
        $levels = static::getContainer()->get(StockLevelRepository::class);
        \assert($levels instanceof StockLevelRepository);
        $holdings = static::getContainer()->get(PlayerHoldingRepository::class);
        \assert($holdings instanceof PlayerHoldingRepository);

        self::assertEquals(['new' => 1, 'worn' => 1], $levels->quantitiesByCondition($helmet, $this->local));
        self::assertSame(['Hugo Durand', 'Léo Martin'], array_map(static fn (PlayerHolding $h): string => $h->getPlayer()->getFullName(), $holdings->findByVariant($helmet)));
        $held = $holdings->findByPlayer($this->player1);
        self::assertCount(1, $held);
        self::assertSame(2, $held[0]->getQuantity());
        self::assertArrayHasKey((int) $held[0]->getId(), $holdings->loanDates($held));
        self::assertTrue($holdings->playerHoldsAnything($this->player1));
        self::assertFalse($holdings->playerHoldsAnything(PlayerFixtures::player($this->em, 'Petit', 'Jean')));
    }

    public function testGiftToTeamOfQuantityUsesAverageCost(): void
    {
        $pucks = StockScenario::variant($this->em, ProductKind::Consumable, 'PALET-1', 'Palet');
        $this->receive($pucks, 10, 100);
        $team = PlayerFixtures::team($this->em, 'U15');

        $operation = $this->recorder->recordGift(new StockGift($this->local, $pucks, 4, null, null, $team));

        self::assertSame(StockMovementType::GiftOut, $operation->getType());
        self::assertSame($this->local->getId(), $operation->getSource()?->getId());
        self::assertSame($team->getId(), $operation->getTeam()?->getId());
        self::assertNull($operation->getPlayer());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(4, $movement->getQuantity());
        self::assertSame(100, $movement->getUnitCostHt());
        self::assertNull($movement->getItem());
        $this->em->clear();
        $level = $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $pucks->getId(), 'location' => $this->local->getId()]);
        self::assertSame(6, $level?->getQuantity());
        self::assertSame(600, $level->getValueHt());
    }

    public function testGiftOfQuantityBeyondStockIsRefused(): void
    {
        $pucks = StockScenario::variant($this->em, ProductKind::Consumable, 'PALET-1', 'Palet');
        $this->receive($pucks, 2, 100);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Stock insuffisant pour « PALET-1 M » à « Local matériel » : 2 disponible(s).');
        $this->recorder->recordGift(new StockGift($this->local, $pucks, 3, null, $this->player1, null));
    }

    public function testGiftNeedsExactlyOneRecipient(): void
    {
        $pucks = StockScenario::variant($this->em, ProductKind::Consumable, 'PALET-1', 'Palet');
        $this->receive($pucks, 10, 100);
        $team = PlayerFixtures::team($this->em, 'U15');
        $before = $this->operations();

        foreach ([[null, null], [$this->player1, $team]] as [$player, $recipientTeam]) {
            try {
                $this->recorder->recordGift(new StockGift($this->local, $pucks, 1, null, $player, $recipientTeam));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame('Choisissez un joueur ou une équipe.', $e->getMessage());
            }
        }

        self::assertSame($before, $this->operations());
    }

    public function testGiftOfItemNeedsConditionAndSingleQuantity(): void
    {
        $helmet = $this->helmet();
        $this->receive($helmet, 2, 15000);

        foreach ([[1, null, 'Choisissez l\'état de la pièce.'], [2, StockCondition::New, 'Une pièce se donne à l\'unité : quantité 1.']] as [$quantity, $condition, $message]) {
            try {
                $this->recorder->recordGift(new StockGift($this->local, $helmet, $quantity, $condition, $this->player1, null));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testGiftOfItemToPlayer(): void
    {
        $helmet = $this->helmet();
        $this->receive($helmet, 1, 15000, StockCondition::Good);
        [$item] = $this->items($helmet);

        $operation = $this->recorder->recordGift(new StockGift($this->local, $helmet, 1, StockCondition::Good, $this->player1, null));

        self::assertSame(StockMovementType::GiftOut, $operation->getType());
        self::assertSame($this->player1->getId(), $operation->getPlayer()?->getId());
        self::assertNull($operation->getTeam());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame($item->getId(), $movement->getItem()?->getId());
        self::assertSame(15000, $movement->getUnitCostHt());
        self::assertSame(ItemStatus::Gifted, $item->getStatus());
        self::assertNull($item->getHolder());
        self::assertSame('Local matériel', $item->getLocation()->getName());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Aucune pièce « CASQUE-1 M » à l\'état Bon disponible à « Local matériel ».');
        $this->recorder->recordGift(new StockGift($this->local, $helmet, 1, StockCondition::Good, $this->player1, null));
    }
}
