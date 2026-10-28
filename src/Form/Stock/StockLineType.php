<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Enum\StockCondition;
use App\Form\StockVariantAutocompleteField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Ligne de sortie ou de transfert : variante, état (équipement) et quantité.
 */
final class StockLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('variant', StockVariantAutocompleteField::class, [
                'label' => 'Article',
                'movable_only' => true,
                'required' => false,
            ])
            ->add('condition', EnumType::class, [
                'label' => 'État',
                'class' => StockCondition::class,
                'choice_label' => static fn (StockCondition $condition): string => $condition->label(),
                'help' => 'Ignoré pour un consommable.',
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'required' => false,
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new GreaterThanOrEqual(1, message: 'La quantité doit être au moins 1.')],
            ]);
    }
}
