<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\SaleLine;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockSale;
use App\Stock\Input\StockSaleLine;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use App\Stock\StockQuery;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SaleRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMovementRecorder $recorder;
    private Location $local;

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
    }

    private function helmet(): Variant
    {
        return StockScenario::variant($this->em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
    }

    private function price(Variant $variant, Season $season, ?int $publicTtc, int $vatRate = 2000): SeasonPrice
    {
        $price = (new SeasonPrice())->setVariant($variant)->setSeason($season)->setPublicPriceTtc($publicTtc)->setVatRate($vatRate);
        $this->em->persist($price);
        $this->em->flush();

        return $price;
    }

    /** Reçoit une pièce et la renvoie. */
    private function receiveItem(Variant $variant, StockCondition $condition = StockCondition::Good, ?Location $location = null, int $cost = 15000): Item
    {
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location ?? $this->local, [new StockEntryLine($variant, 1, $cost, $condition)]));
        $item = $this->em->getRepository(Item::class)->findOneBy(['variant' => $variant], ['id' => 'DESC']);
        \assert($item instanceof Item);

        return $item;
    }

    private function sell(Item $item, ?int $discountRate, ?int $finalPriceTtc = null, ?Location $location = null, string $buyer = 'Mme Dupont, 06 12 34 56 78', ?Player $player = null): StockOperation
    {
        return $this->recorder->recordSale(new StockSale($location ?? $this->local, $buyer, [new StockSaleLine($item, $discountRate, $finalPriceTtc)], $player));
    }

    private function line(StockOperation $operation): SaleLine
    {
        $line = $operation->getSale()?->getLines()->first();
        self::assertInstanceOf(SaleLine::class, $line);

        return $line;
    }

    private function resetManager(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        \assert($doctrine instanceof ManagerRegistry);
        $doctrine->resetManager();
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
        $entity = $this->em->getRepository($class)->find($id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    private function totalValueHt(): int
    {
        $query = static::getContainer()->get(StockQuery::class);
        \assert($query instanceof StockQuery);

        return $query->totalValueHt(null);
    }

    public function testSaleUsesCurrentPublicPriceAndFreezesIt(): void
    {
        $helmet = $this->helmet();
        $current = PlayerFixtures::season($this->em, 2026, true);
        $tariff = $this->price($helmet, $current, 30000);
        $item = $this->receiveItem($helmet, StockCondition::Good);
        $player = PlayerFixtures::player($this->em, 'Martin', 'Léo');
        self::assertSame(15000, $this->totalValueHt());

        $operation = $this->sell($item, 8000, null, null, '  Mme Dupont  ', $player);

        self::assertSame(StockMovementType::SaleOut, $operation->getType());
        self::assertSame($this->local->getId(), $operation->getSource()?->getId());
        self::assertNull($operation->getDestination());
        self::assertSame($player->getId(), $operation->getPlayer()?->getId());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(1, $movement->getQuantity());
        self::assertSame(15000, $movement->getUnitCostHt());
        self::assertSame($item->getId(), $movement->getItem()?->getId());
        $sale = $operation->getSale();
        self::assertNotNull($sale);
        self::assertSame('Mme Dupont', $sale->getBuyer());
        self::assertSame($player->getId(), $sale->getPlayer()?->getId());
        self::assertSame($operation, $sale->getOperation());
        $line = $this->line($operation);
        self::assertSame(30000, $line->getBasePriceTtc());
        self::assertSame(8000, $line->getDiscountRate());
        self::assertSame(2000, $line->getVatRate());
        self::assertSame(6000, $line->getPriceTtc());
        self::assertSame(5000, $line->getPriceHt());
        self::assertSame(6000, $sale->getTotalTtc());
        self::assertSame(ItemStatus::Sold, $item->getStatus());
        self::assertSame('Local', $item->getLocation()->getName());
        self::assertSame(0, $this->totalValueHt());

        // Un changement de tarif après coup ne touche pas la vente.
        $tariff->setPublicPriceTtc(50000);
        $this->em->flush();
        $this->em->clear();
        $line = $this->reload(SaleLine::class, $line->getId());
        self::assertSame(30000, $line->getBasePriceTtc());
        self::assertSame(6000, $line->getPriceTtc());
        self::assertSame(5000, $line->getPriceHt());
        $sale = $line->getSale();
        self::assertSame($operation->getId(), $sale->getOperation()->getId());
        self::assertSame($sale->getId(), $this->reload(StockOperation::class, $operation->getId())->getSale()?->getId());
    }

    public function testFallsBackToLatestSeasonWithPublicPrice(): void
    {
        $helmet = $this->helmet();
        PlayerFixtures::season($this->em, 2026, true);
        $this->price($helmet, PlayerFixtures::season($this->em, 2024), 20000);
        $this->price($helmet, PlayerFixtures::season($this->em, 2025), 25000, 550);
        $item = $this->receiveItem($helmet);

        $line = $this->line($this->sell($item, 8000));

        self::assertSame(25000, $line->getBasePriceTtc());
        self::assertSame(550, $line->getVatRate());
        self::assertSame(5000, $line->getPriceTtc());
    }

    public function testFallbackIgnoresFutureSeasons(): void
    {
        $helmet = $this->helmet();
        $current = PlayerFixtures::season($this->em, 2026, true);
        $this->price($helmet, $current, null);
        $this->price($helmet, PlayerFixtures::season($this->em, 2025), 25000);
        $this->price($helmet, PlayerFixtures::season($this->em, 2027), 40000);
        $item = $this->receiveItem($helmet);

        $line = $this->line($this->sell($item, 8000));

        self::assertSame(25000, $line->getBasePriceTtc());
        self::assertSame(5000, $line->getPriceTtc());
    }

    public function testFinalPriceOverridesDiscount(): void
    {
        $helmet = $this->helmet();
        $this->price($helmet, PlayerFixtures::season($this->em, 2026, true), 30000);
        $item = $this->receiveItem($helmet);

        $line = $this->line($this->sell($item, 8000, 4500));

        self::assertSame(30000, $line->getBasePriceTtc());
        self::assertSame(8000, $line->getDiscountRate());
        self::assertSame(4500, $line->getPriceTtc());
        self::assertSame(3750, $line->getPriceHt());
    }

    public function testSaleWithoutPublicPriceNeedsFinalPrice(): void
    {
        $helmet = $this->helmet();
        $item = $this->receiveItem($helmet);

        try {
            $this->sell($item, 8000);
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame('Saisissez le prix de vente de « CASQUE-1 M ».', $e->getMessage());
        }

        $this->resetManager();
        $item = $this->reload(Item::class, $item->getId());
        $this->local = $this->reload(Location::class, $this->local->getId());
        self::assertSame(ItemStatus::InStock, $item->getStatus());

        $line = $this->line($this->sell($item, null, 2000));

        self::assertNull($line->getBasePriceTtc());
        self::assertNull($line->getDiscountRate());
        self::assertSame(SeasonPrice::DEFAULT_VAT_RATE, $line->getVatRate());
        self::assertSame(2000, $line->getPriceTtc());
        self::assertSame(1667, $line->getPriceHt());
    }

    public function testNegativeFinalPriceIsRefused(): void
    {
        $item = $this->receiveItem($this->helmet());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le prix de vente ne peut pas être négatif.');
        $this->sell($item, null, -1);
    }

    public function testSaleRejectsNewOrOtherLocationItem(): void
    {
        $helmet = $this->helmet();
        $this->price($helmet, PlayerFixtures::season($this->em, 2026, true), 30000);
        $reserve = StockFixtures::location($this->em, 'Réserve');
        $new = $this->receiveItem($helmet, StockCondition::New);
        $elsewhere = $this->receiveItem($helmet, StockCondition::Good, $reserve);

        $cases = [
            [$new->getId(), \sprintf('La pièce %s est neuve : seule l\'occasion se vend.', $new->getCode())],
            [$elsewhere->getId(), \sprintf('La pièce %s n\'est pas en stock à « Local ».', $elsewhere->getCode())],
        ];
        foreach ($cases as [$id, $message]) {
            $this->resetManager();
            $item = $this->reload(Item::class, $id);
            $local = $this->reload(Location::class, $this->local->getId());
            try {
                $this->sell($item, 8000, null, $local);
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $this->resetManager();
        self::assertSame(0, (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o WHERE o.type = :type')->setParameter('type', StockMovementType::SaleOut)->getSingleScalarResult());
    }

    public function testSameItemTwiceAndEmptySaleAreRefused(): void
    {
        $helmet = $this->helmet();
        $item = $this->receiveItem($helmet);

        foreach ([[[], 'Ajoutez au moins une ligne.'], [[new StockSaleLine($item, null, 1000), new StockSaleLine($item, null, 1000)], \sprintf('La pièce %s figure deux fois.', $item->getCode())]] as [$lines, $message]) {
            try {
                $this->recorder->recordSale(new StockSale($this->local, 'Mme Dupont', $lines));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testBuyerIsRequired(): void
    {
        $item = $this->receiveItem($this->helmet());

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Indiquez l\'acheteur.');
        $this->sell($item, null, 2000, null, '  ');
    }
}
