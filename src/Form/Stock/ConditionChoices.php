<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Enum\StockCondition;

/**
 * Options d'un champ « état » dont les libellés portent la quantité disponible dans le lieu choisi ;
 * les états sans stock sont désactivés.
 */
final class ConditionChoices
{
    /**
     * @param array<string, int> $counts
     *
     * @return array<string, mixed>
     */
    public static function options(string $label, array $counts): array
    {
        return [
            'label' => $label,
            'class' => StockCondition::class,
            'expanded' => true,
            'choice_label' => static fn (StockCondition $condition): string => \sprintf('%s (%d)', $condition->label(), $counts[$condition->value] ?? 0),
            'choice_attr' => static fn (StockCondition $condition): array => 0 === ($counts[$condition->value] ?? 0) ? ['disabled' => 'disabled'] : [],
        ];
    }
}
