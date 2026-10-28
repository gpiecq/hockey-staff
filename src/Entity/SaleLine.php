<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockCondition;
use App\Sale\SalePrice;
use App\Sale\SalePricing;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne immuable d'une vente : une quantité d'occasion (variante × état) et son prix **unitaire** figé
 * (centimes, taux en points de base) ; total de ligne = quantité × prix unitaire.
 */
#[ORM\Entity]
class SaleLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Sale $sale;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Variant $variant;

    #[ORM\Column(length: 20, enumType: StockCondition::class)]
    private StockCondition $condition;

    #[ORM\Column]
    private int $quantity;

    /** Prix public TTC de référence ; null sans tarif public. */
    #[ORM\Column(nullable: true)]
    private ?int $basePriceTtc;

    /** Réduction en points de base (80 % = 8000). */
    #[ORM\Column(nullable: true)]
    private ?int $discountRate;

    #[ORM\Column]
    private int $vatRate;

    /** Prix unitaire TTC. */
    #[ORM\Column]
    private int $priceTtc;

    /** Prix unitaire HT. */
    #[ORM\Column]
    private int $priceHt;

    public function __construct(Sale $sale, Variant $variant, StockCondition $condition, int $quantity, SalePrice $unitPrice)
    {
        $this->sale = $sale;
        $this->variant = $variant;
        $this->condition = $condition;
        $this->quantity = $quantity;
        $this->basePriceTtc = $unitPrice->basePriceTtc;
        $this->discountRate = $unitPrice->discountRate;
        $this->vatRate = $unitPrice->vatRate;
        $this->priceTtc = $unitPrice->priceTtc;
        $this->priceHt = $unitPrice->priceHt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }

    public function getCondition(): StockCondition
    {
        return $this->condition;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getBasePriceTtc(): ?int
    {
        return $this->basePriceTtc;
    }

    public function getDiscountRate(): ?int
    {
        return $this->discountRate;
    }

    public function getVatRate(): int
    {
        return $this->vatRate;
    }

    public function getPriceTtc(): int
    {
        return $this->priceTtc;
    }

    public function getPriceHt(): int
    {
        return $this->priceHt;
    }

    /** Total TTC de la ligne : quantité × prix unitaire TTC. */
    public function getTotalTtc(): int
    {
        return $this->quantity * $this->priceTtc;
    }

    /** Total HT de la ligne : quantité × prix unitaire HT (pas de recalcul depuis le total TTC). */
    public function getTotalHt(): int
    {
        return $this->quantity * $this->priceHt;
    }

    /** Faux quand un prix final a remplacé la réduction saisie (la réduction reste enregistrée, pour mémoire). */
    public function isDiscountApplied(): bool
    {
        return SalePricing::isDiscountApplied($this->basePriceTtc, $this->discountRate, $this->priceTtc);
    }
}
