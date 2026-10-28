<?php

declare(strict_types=1);

namespace App\Form\Sale;

use App\Entity\Variant;
use App\Enum\StockCondition;
use App\Stock\Input\StockSaleLine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * Traduit les lignes saisies en StockSaleLine (choix « <variantId>-<état> », réduction % → points de base) ;
 * les erreurs de saisie sont portées par les champs de la ligne. Les règles métier (neuf, consommable, doublon,
 * stock) restent au StockMovementRecorder.
 */
final readonly class SaleLineMapper
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<StockSaleLine>|null null si au moins une ligne est invalide (erreurs ajoutées au formulaire)
     */
    public function map(FormInterface $lines): ?array
    {
        $result = [];
        $valid = true;
        foreach ($lines as $line) {
            /** @var array{stock?: ?string, quantity?: ?int, discount?: ?int, finalPrice?: ?int} $row */
            $row = $line->getData();
            $stock = $this->parse($row['stock'] ?? null);
            if (null === $stock) {
                $line->get('stock')->addError(new FormError('Choisissez l\'article d\'occasion.'));
                $valid = false;
                continue;
            }
            $discount = $row['discount'] ?? null;
            $result[] = new StockSaleLine($stock[0], $stock[1], $row['quantity'] ?? 1, null === $discount ? null : $discount * 100, $row['finalPrice'] ?? null);
        }

        return $valid ? $result : null;
    }

    /**
     * @return array{Variant, StockCondition}|null
     */
    public function parse(mixed $value): ?array
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d+)-([a-z]+)$/', $value, $matches)) {
            return null;
        }
        $variant = $this->em->find(Variant::class, (int) $matches[1]);
        $condition = StockCondition::tryFrom($matches[2]);

        return null === $variant || null === $condition ? null : [$variant, $condition];
    }
}
