<?php

declare(strict_types=1);

namespace App\Sale;

use App\Catalogue\CurrentSeasonProvider;
use App\Catalogue\Money\VatCalculator;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
use App\Repository\SeasonPriceRepository;
use App\Stock\StockException;

/**
 * Prix de vente d'occasion : prix public TTC de référence × (1 − réduction), ou prix final saisi.
 */
final readonly class SalePricing
{
    public function __construct(
        private SeasonPriceRepository $prices,
        private CurrentSeasonProvider $seasons,
    ) {
    }

    /**
     * Prix TTC remisé, arrondi au centime le plus proche (demi vers le haut).
     */
    public static function discounted(int $basePriceTtc, int $discountRate): int
    {
        if ($discountRate < 0 || $discountRate > 10000) {
            throw new \InvalidArgumentException(\sprintf('Réduction hors de 0–10000 points de base : %d.', $discountRate));
        }

        return intdiv($basePriceTtc * (10000 - $discountRate) + 5000, 10000);
    }

    public function price(Variant $variant, ?int $discountRate, ?int $finalPriceTtc): SalePrice
    {
        if (null !== $discountRate && ($discountRate < 0 || $discountRate > 10000)) {
            throw new StockException('La réduction doit être comprise entre 0 et 100 %.');
        }
        if (null !== $finalPriceTtc && $finalPriceTtc < 0) {
            throw new StockException('Le prix de vente ne peut pas être négatif.');
        }

        $reference = $this->prices->findPublicReference($variant, $this->seasons->get());
        $basePriceTtc = $reference?->getPublicPriceTtc();
        $vatRate = $reference?->getVatRate() ?? SeasonPrice::DEFAULT_VAT_RATE;

        if (null !== $finalPriceTtc) {
            $priceTtc = $finalPriceTtc;
        } elseif (null !== $basePriceTtc && null !== $discountRate) {
            $priceTtc = self::discounted($basePriceTtc, $discountRate);
        } else {
            $label = trim(($variant->getProduct()?->getReference() ?? '').' '.$variant->getLabel());

            throw new StockException(\sprintf('Saisissez le prix de vente de « %s ».', $label));
        }

        return new SalePrice($basePriceTtc, $discountRate, $vatRate, $priceTtc, VatCalculator::htFromTtc($priceTtc, $vatRate));
    }
}
