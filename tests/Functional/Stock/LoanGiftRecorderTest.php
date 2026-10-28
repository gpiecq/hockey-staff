<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Repository\ItemRepository;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockReturn;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $recorder = static::getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $this->recorder = $recorder;
        $this->local = StockFixtures::location($this->em, 'Local');
        $this->player1 = PlayerFixtures::player($this->em, 'Martin', 'Léo');
        $this->player6 = PlayerFixtures::player($this->em, 'Durand', 'Hugo');
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

    private function resetManager(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        \assert($doctrine instanceof ManagerRegistry);
        $doctrine->resetManager();
    }

    private function operations(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o')->getSingleScalarResult();
    }

    public function testLoanPicksOldestAndFailsWhenNoneLeft(): void
    {
        $helmet = $this->helmet();
        $this->receive($helmet, 2, 15000);
        $this->receive($helmet, 1, 15000, StockCondition::Worn);
        [$first, $second] = $this->items($helmet);

        $operation = $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New));

        self::assertSame(StockMovementType::LoanOut, $operation->getType());
        self::assertSame($this->local->getId(), $operation->getSource()?->getId());
        self::assertNull($operation->getDestination());
        self::assertSame($this->player1->getId(), $operation->getPlayer()?->getId());
        self::assertNull($operation->getTeam());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(1, $movement->getQuantity());
        self::assertSame(15000, $movement->getUnitCostHt());
        self::assertSame($first->getId(), $movement->getItem()?->getId());
        self::assertSame(ItemStatus::Loaned, $first->getStatus());
        self::assertSame($this->player1->getId(), $first->getHolder()?->getId());
        self::assertSame('Local', $first->getLocation()->getName());

        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New));
        self::assertSame(ItemStatus::Loaned, $second->getStatus());

        $this->recorder->recordLoan(new StockLoan($this->local, $this->player6, $helmet, StockCondition::Worn));
        $worn = $this->items($helmet)[2];
        self::assertSame(ItemStatus::Loaned, $worn->getStatus());
        self::assertSame($this->player6->getId(), $worn->getHolder()?->getId());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Aucune pièce « CASQUE-1 M » à l\'état Neuf disponible à « Local ».');
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New));
    }

    public function testQuantityProductCannotBeLoaned(): void
    {
        $pucks = StockScenario::variant($this->em, ProductKind::Consumable, 'PALET-1', 'Palet');
        $this->receive($pucks, 10, 100);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('« PALET-1 M » est suivi en quantité : il ne se prête pas.');
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $pucks, StockCondition::New));
    }

    public function testLoanFromInactiveLocationIsRefused(): void
    {
        $helmet = $this->helmet();
        $cave = StockFixtures::location($this->em, 'Cave', false);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le lieu « Cave » est inactif.');
        $this->recorder->recordLoan(new StockLoan($cave, $this->player1, $helmet, StockCondition::New));
    }

    public function testReturnRejectsWrongHolderOrNotLoaned(): void
    {
        $helmet = $this->helmet();
        $this->receive($helmet, 1, 15000);
        $reserve = StockFixtures::location($this->em, 'Réserve');
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New));
        [$item] = $this->items($helmet);
        $before = $this->operations();

        try {
            $this->recorder->recordReturn(new StockReturn($item, $this->player6, $reserve, StockCondition::Worn));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame(\sprintf('La pièce %s n\'est pas prêtée à Hugo Durand.', $item->getCode()), $e->getMessage());
        }
        self::assertSame($before, $this->operations());

        // Un refus dans la transaction ferme l'EntityManager (comme en requête réelle) : on repart d'un EM neuf.
        $this->resetManager();
        $item = $this->em->getRepository(Item::class)->find($item->getId());
        $player1 = $this->em->getRepository(Player::class)->find($this->player1->getId());
        $reserve = $this->em->getRepository(Location::class)->find($reserve->getId());
        \assert($item instanceof Item && $player1 instanceof Player && $reserve instanceof Location);
        self::assertSame(ItemStatus::Loaned, $item->getStatus());

        $operation = $this->recorder->recordReturn(new StockReturn($item, $player1, $reserve, StockCondition::Worn, 'Fin de saison'));

        self::assertSame(StockMovementType::LoanReturn, $operation->getType());
        self::assertNull($operation->getSource());
        self::assertSame($reserve->getId(), $operation->getDestination()?->getId());
        self::assertSame($player1->getId(), $operation->getPlayer()?->getId());
        self::assertSame('Fin de saison', $operation->getComment());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(15000, $movement->getUnitCostHt());
        $this->em->clear();
        $fresh = $this->em->getRepository(Item::class)->find($item->getId());
        self::assertSame(ItemStatus::InStock, $fresh?->getStatus());
        self::assertNull($fresh->getHolder());
        self::assertSame('Réserve', $fresh->getLocation()->getName());
        self::assertSame(StockCondition::Worn, $fresh->getCondition());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage(\sprintf('La pièce %s n\'est pas prêtée à Léo Martin.', $item->getCode()));
        $player1 = $this->em->getRepository(Player::class)->find($this->player1->getId());
        $reserve = $this->em->getRepository(Location::class)->find($reserve->getId());
        \assert($player1 instanceof Player && $reserve instanceof Location);
        $this->recorder->recordReturn(new StockReturn($fresh, $player1, $reserve, StockCondition::Worn));
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
        $this->expectExceptionMessage('Stock insuffisant pour « PALET-1 M » à « Local » : 2 disponible(s).');
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
        self::assertSame('Local', $item->getLocation()->getName());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Aucune pièce « CASQUE-1 M » à l\'état Bon disponible à « Local ».');
        $this->recorder->recordGift(new StockGift($this->local, $helmet, 1, StockCondition::Good, $this->player1, null));
    }

    public function testRepositoryCountsByConditionAndListsLoans(): void
    {
        $helmet = $this->helmet();
        $this->receive($helmet, 3, 15000);
        $this->receive($helmet, 1, 15000, StockCondition::Worn);
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player1, $helmet, StockCondition::New));
        $this->recorder->recordLoan(new StockLoan($this->local, $this->player6, $helmet, StockCondition::New));
        $repository = static::getContainer()->get(ItemRepository::class);
        \assert($repository instanceof ItemRepository);

        self::assertEquals(['new' => 1, 'worn' => 1], $repository->countInStockByCondition($helmet, $this->local));
        self::assertSame(['Hugo Durand', 'Léo Martin'], array_map(static fn (Item $item): string => (string) $item->getHolder()?->getFullName(), $repository->findLoanedByVariant($helmet)));
        $loaned = $repository->findLoanedByPlayer($this->player1);
        self::assertCount(1, $loaned);
        self::assertSame($this->items($helmet)[0]->getId(), $loaned[0]->getId());
    }
}
