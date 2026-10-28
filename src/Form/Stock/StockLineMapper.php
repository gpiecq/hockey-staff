<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Variant;
use App\Enum\StockCondition;
use App\Stock\Input\StockLine;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * Traduit les lignes saisies (variante, état, quantité) en StockLine ;
 * les erreurs de saisie sont portées par les champs de la ligne.
 */
final readonly class StockLineMapper
{
    /**
     * @return list<StockLine>|null null si au moins une ligne est invalide (erreurs ajoutées au formulaire)
     */
    public function map(FormInterface $lines): ?array
    {
        $result = [];
        $valid = true;
        foreach ($lines as $line) {
            /** @var array{variant?: ?Variant, condition?: ?StockCondition, quantity?: ?int} $row */
            $row = $line->getData();
            $variant = $row['variant'] ?? null;
            $quantity = $row['quantity'] ?? null;

            if (null === $variant) {
                $line->get('variant')->addError(new FormError('Choisissez un article.'));
                $valid = false;
            } elseif (null === $quantity) {
                $line->get('quantity')->addError(new FormError('Indiquez la quantité.'));
                $valid = false;
            } else {
                $result[] = new StockLine($variant, $quantity, $row['condition'] ?? StockCondition::New);
            }
        }

        return $valid ? $result : null;
    }
}
