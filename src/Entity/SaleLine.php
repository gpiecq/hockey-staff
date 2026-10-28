<?php

declare(strict_types=1);

namespace App\Entity;

use App\Sale\SalePrice;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne immuable d'une vente : la pièce vendue et son prix figé (centimes, taux en points de base).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_sale_line_item', columns: ['item_id'])]
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
    private Item $item;

    /** Prix public TTC de référence ; null sans tarif public. */
    #[ORM\Column(nullable: true)]
    private ?int $basePriceTtc;

    /** Réduction en points de base (80 % = 8000). */
    #[ORM\Column(nullable: true)]
    private ?int $discountRate;

    #[ORM\Column]
    private int $vatRate;

    #[ORM\Column]
    private int $priceTtc;

    #[ORM\Column]
    private int $priceHt;

    public function __construct(Sale $sale, Item $item, SalePrice $price)
    {
        $this->sale = $sale;
        $this->item = $item;
        $this->basePriceTtc = $price->basePriceTtc;
        $this->discountRate = $price->discountRate;
        $this->vatRate = $price->vatRate;
        $this->priceTtc = $price->priceTtc;
        $this->priceHt = $price->priceHt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function getItem(): Item
    {
        return $this->item;
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
}
