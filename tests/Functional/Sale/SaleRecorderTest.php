<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Stock\Input\StockSale;
use App\Stock\Input\StockSaleLine;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
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
        $this->local = StockFixtures::location($this->em, 'Local matériel');
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

    private function sell(Variant $variant, int $quantity, ?int $discountRate, ?int $finalPriceTtc = null, StockCondition $condition = StockCondition::Worn, string $buyer = 'Mme Dupont, 06 12 34 56 78', ?Player $player = null): StockOperation
    {
        return $this->recorder->recordSale(new StockSale($this->local, $buyer, [new StockSaleLine($variant, $condition, $quantity, $discountRate, $finalPriceTtc)], $player));
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
        $em = $doctrine->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
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

    private function saleOutCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o WHERE o.type = :type')->setParameter('type', StockMovementType::SaleOut)->getSingleScalarResult();
    }

    public function testSaleOfSecondHandQuantityFreezesUnitPrices(): void
    {
        $helmet = $this->helmet();
        $tariff = $this->price($helmet, PlayerFixtures::season($this->em, 2026, true), 10000);
        $level = StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Worn);
        $player = PlayerFixtures::player($this->em, 'Martin', 'Léo');

        $operation = $this->sell($helmet, 2, 8000, null, StockCondition::Worn, '  Mme Dupont  ', $player);

        self::assertSame(StockMovementType::SaleOut, $operation->getType());
        self::assertSame($this->local->getId(), $operation->getSource()?->getId());
        self::assertNull($operation->getDestination());
        self::assertSame($player->getId(), $operation->getPlayer()?->getId());
        self::assertCount(1, $operation->getMovements());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(2, $movement->getQuantity());
        self::assertSame(StockCondition::Worn, $movement->getCondition());
        self::assertSame(15000, $movement->getUnitCostHt());
        $sale = $operation->getSale();
        self::assertNotNull($sale);
        self::assertSame('Mme Dupont', $sale->getBuyer());
        self::assertSame($player->getId(), $sale->getPlayer()?->getId());
        $line = $this->line($operation);
        self::assertSame($helmet->getId(), $line->getVariant()->getId());
        self::assertSame(StockCondition::Worn, $line->getCondition());
        self::assertSame(2, $line->getQuantity());
        self::assertSame(10000, $line->getBasePriceTtc());
        self::assertSame(8000, $line->getDiscountRate());
        self::assertSame(2000, $line->getVatRate());
        self::assertSame(2000, $line->getPriceTtc());
        self::assertSame(1667, $line->getPriceHt());
        self::assertSame(4000, $line->getTotalTtc());
        self::assertSame(3334, $line->getTotalHt());
        self::assertSame(4000, $sale->getTotalTtc());
        self::assertSame(1, $level->getQuantity());
        self::assertSame(15000, $level->getValueHt());

        // Un changement de tarif après coup ne touche pas la vente.
        $tariff->setPublicPriceTtc(50000);
        $this->em->flush();
        $this->em->clear();
        $line = $this->reload(SaleLine::class, $line->getId());
        self::assertSame(10000, $line->getBasePriceTtc());
        self::assertSame(2000, $line->getPriceTtc());
        self::assertSame(2, $line->getQuantity());
        self::assertSame(StockCondition::Worn, $line->getCondition());
        self::assertSame($operation->getId(), $line->getSale()->getOperation()->getId());
    }

    public function testFallsBackToLatestSeasonWithPublicPrice(): void
    {
        $helmet = $this->helmet();
        PlayerFixtures::season($this->em, 2026, true);
        $this->price($helmet, PlayerFixtures::season($this->em, 2024), 20000);
        $this->price($helmet, PlayerFixtures::season($this->em, 2025), 25000, 550);
        $this->price($helmet, PlayerFixtures::season($this->em, 2027), 40000);
        StockFixtures::level($this->em, $helmet, $this->local, 1, 15000, StockCondition::Good);

        $line = $this->line($this->sell($helmet, 1, 8000, null, StockCondition::Good));

        self::assertSame(25000, $line->getBasePriceTtc());
        self::assertSame(550, $line->getVatRate());
        self::assertSame(5000, $line->getPriceTtc());
    }

    public function testFinalUnitPriceOverridesDiscount(): void
    {
        $helmet = $this->helmet();
        $this->price($helmet, PlayerFixtures::season($this->em, 2026, true), 30000);
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Damaged);

        $line = $this->line($this->sell($helmet, 3, 8000, 4500, StockCondition::Damaged));

        self::assertSame(8000, $line->getDiscountRate());
        self::assertFalse($line->isDiscountApplied());
        self::assertSame(4500, $line->getPriceTtc());
        self::assertSame(3750, $line->getPriceHt());
        self::assertSame(13500, $line->getTotalTtc());
        self::assertSame(11250, $line->getTotalHt());
    }

    public function testSaleWithoutPublicPriceNeedsFinalPrice(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Worn);

        try {
            $this->sell($helmet, 2, 8000);
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame('Saisissez le prix de vente de « CASQUE-1 M ».', $e->getMessage());
        }

        $this->resetManager();
        $helmet = $this->reload(Variant::class, $helmet->getId());
        $this->local = $this->reload(Location::class, $this->local->getId());
        self::assertSame(0, $this->saleOutCount());

        $line = $this->line($this->sell($helmet, 2, null, 2000));

        self::assertNull($line->getBasePriceTtc());
        self::assertNull($line->getDiscountRate());
        self::assertSame(SeasonPrice::DEFAULT_VAT_RATE, $line->getVatRate());
        self::assertSame(2000, $line->getPriceTtc());
        self::assertSame(1667, $line->getPriceHt());
        self::assertSame(4000, $line->getTotalTtc());
        $level = $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $helmet, 'condition' => StockCondition::Worn]);
        self::assertInstanceOf(StockLevel::class, $level);
        self::assertSame(1, $level->getQuantity());
    }

    public function testNegativeFinalPriceIsRefused(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 1, 15000, StockCondition::Worn);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le prix de vente ne peut pas être négatif.');
        $this->sell($helmet, 1, null, -1);
    }

    public function testSaleRejectsNewConsumableShortOrOtherLocation(): void
    {
        $helmet = $this->helmet();
        $this->price($helmet, PlayerFixtures::season($this->em, 2026, true), 30000);
        $tape = StockScenario::variant($this->em, ProductKind::Consumable, 'TAPE-1', 'Ruban');
        $gloves = StockScenario::variant($this->em, ProductKind::Equipment, 'GANTS-1', 'Gants');
        $vestiaire = StockFixtures::location($this->em, 'Vestiaire');
        StockFixtures::level($this->em, $helmet, $this->local, 2, 15000);
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Worn);
        StockFixtures::level($this->em, $tape, $this->local, 10, 100);
        StockFixtures::level($this->em, $gloves, $vestiaire, 2, 5000, StockCondition::Worn);

        $cases = [
            [$helmet->getId(), StockCondition::New, 1, '« CASQUE-1 M » neuf : seule l\'occasion se vend.'],
            [$tape->getId(), StockCondition::New, 1, '« TAPE-1 M » est un consommable : il ne se vend pas.'],
            [$helmet->getId(), StockCondition::Worn, 4, 'Stock insuffisant pour « CASQUE-1 M » (Usé) à « Local matériel » : 3 disponible(s).'],
            [$gloves->getId(), StockCondition::Worn, 1, 'Stock insuffisant pour « GANTS-1 M » (Usé) à « Local matériel » : 0 disponible(s).'],
        ];
        foreach ($cases as [$id, $condition, $quantity, $message]) {
            $this->resetManager();
            $variant = $this->reload(Variant::class, $id);
            $this->local = $this->reload(Location::class, $this->local->getId());
            try {
                $this->sell($variant, $quantity, null, 1000, $condition);
                self::fail('StockException attendue : '.$message);
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $this->resetManager();
        self::assertSame(0, $this->saleOutCount());
        self::assertSame(0, $this->em->getRepository(Sale::class)->count([]));
    }

    public function testDuplicateVariantConditionLineRejected(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Worn);
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Good);

        try {
            $this->recorder->recordSale(new StockSale($this->local, 'Mme Dupont', [
                new StockSaleLine($helmet, StockCondition::Worn, 1, null, 1000),
                new StockSaleLine($helmet, StockCondition::Good, 1, null, 1000),
                new StockSaleLine($helmet, StockCondition::Worn, 1, null, 1000),
            ]));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame('« CASQUE-1 M » (Usé) figure deux fois : augmentez la quantité.', $e->getMessage());
        }
        self::assertSame(0, $this->saleOutCount());
    }

    public function testSeveralLinesMakeOneOperation(): void
    {
        $helmet = $this->helmet();
        $gloves = StockScenario::variant($this->em, ProductKind::Equipment, 'GANTS-1', 'Gants');
        StockFixtures::level($this->em, $gloves, $this->local, 2, 5000, StockCondition::Good);
        StockFixtures::level($this->em, $helmet, $this->local, 3, 15000, StockCondition::Worn);

        $operation = $this->recorder->recordSale(new StockSale($this->local, 'Mme Dupont', [
            new StockSaleLine($helmet, StockCondition::Worn, 2, null, 1000),
            new StockSaleLine($gloves, StockCondition::Good, 1, null, 500),
        ]));

        self::assertCount(2, $operation->getMovements());
        self::assertSame(2500, $operation->getSale()?->getTotalTtc());
    }

    public function testEmptySaleZeroQuantityAndBlankBuyerAreRefused(): void
    {
        $helmet = $this->helmet();
        StockFixtures::level($this->em, $helmet, $this->local, 1, 15000, StockCondition::Worn);

        $cases = [
            ['Mme Dupont', [], 'Ajoutez au moins une ligne.'],
            ['Mme Dupont', [new StockSaleLine($helmet, StockCondition::Worn, 0, null, 1000)], 'La quantité doit être au moins 1.'],
            ['  ', [new StockSaleLine($helmet, StockCondition::Worn, 1, null, 1000)], 'Indiquez l\'acheteur.'],
        ];
        foreach ($cases as [$buyer, $lines, $message]) {
            try {
                $this->recorder->recordSale(new StockSale($this->local, $buyer, $lines));
                self::fail('StockException attendue.');
            } catch (StockException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
