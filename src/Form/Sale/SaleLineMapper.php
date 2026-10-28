<?php

declare(strict_types=1);

namespace App\Form\Sale;

use App\Repository\ItemRepository;
use App\Stock\Input\StockSaleLine;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * Traduit les lignes saisies en StockSaleLine (réduction % → points de base) ;
 * les erreurs de saisie sont portées par les champs de la ligne.
 */
final readonly class SaleLineMapper
{
    public function __construct(private ItemRepository $items)
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
            /** @var array{itemCode?: ?string, discount?: ?int, finalPrice?: ?int} $row */
            $row = $line->getData();
            $code = strtoupper(trim($row['itemCode'] ?? ''));
            if ('' === $code) {
                $line->get('itemCode')->addError(new FormError('Indiquez le code de la pièce.'));
                $valid = false;
                continue;
            }
            $item = $this->items->findOneByCode($code);
            if (null === $item) {
                $line->get('itemCode')->addError(new FormError(\sprintf('Pièce %s introuvable.', $code)));
                $valid = false;
                continue;
            }
            $discount = $row['discount'] ?? null;
            $result[] = new StockSaleLine($item, null === $discount ? null : $discount * 100, $row['finalPrice'] ?? null);
        }

        return $valid ? $result : null;
    }
}
