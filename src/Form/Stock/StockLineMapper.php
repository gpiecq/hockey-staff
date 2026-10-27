<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Variant;
use App\Repository\ItemRepository;
use App\Stock\Input\StockLine;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * Traduit les lignes saisies (code de pièce ou variante + quantité) en StockLine ;
 * les erreurs de saisie sont portées par les champs de la ligne.
 */
final readonly class StockLineMapper
{
    public function __construct(private ItemRepository $items)
    {
    }

    /**
     * @return list<StockLine>|null null si au moins une ligne est invalide (erreurs ajoutées au formulaire)
     */
    public function map(FormInterface $lines): ?array
    {
        $result = [];
        $valid = true;
        foreach ($lines as $line) {
            /** @var array{itemCode?: ?string, variant?: ?Variant, quantity?: ?int} $row */
            $row = $line->getData();
            $code = strtoupper(trim($row['itemCode'] ?? ''));
            $variant = $row['variant'] ?? null;
            $quantity = $row['quantity'] ?? null;

            if ('' !== $code) {
                $item = $this->items->findOneByCode($code);
                if (null === $item) {
                    $line->get('itemCode')->addError(new FormError(\sprintf('Pièce %s introuvable.', $code)));
                    $valid = false;
                    continue;
                }
                $result[] = new StockLine($item->getVariant(), 1, $item);
            } elseif (null === $variant) {
                $line->get('variant')->addError(new FormError('Indiquez une variante ou un code de pièce.'));
                $valid = false;
            } elseif (null === $quantity) {
                $line->get('quantity')->addError(new FormError('Indiquez la quantité.'));
                $valid = false;
            } else {
                $result[] = new StockLine($variant, $quantity);
            }
        }

        return $valid ? $result : null;
    }
}
