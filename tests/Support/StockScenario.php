<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockMovementRecorder;
use Doctrine\ORM\EntityManagerInterface;

/** Mise en place de stock pour les tests de lecture (passe par le recorder, comme le code réel). */
final class StockScenario
{
    public static function variant(EntityManagerInterface $em, ProductKind $mode, string $reference, string $designation, ?string $ean = null): Variant
    {
        $supplier = $em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($em);
        $product = ImportFixtures::product($em, $supplier, $reference, $designation);
        $product->setKind($mode);
        $variant = ImportFixtures::variant($em, $product, $ean, 'M');
        $em->flush();

        return $variant;
    }

    /** Achat : crée ou alimente le niveau (variante, lieu, état) pour tout type de produit (état forcé à Neuf pour un consommable). */
    public static function receive(StockMovementRecorder $recorder, Variant $variant, Location $location, int $quantity, int $unitCostHt, StockCondition $condition = StockCondition::New): void
    {
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, $quantity, $unitCostHt, $condition)]));
    }
}
