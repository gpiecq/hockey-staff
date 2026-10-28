<?php

declare(strict_types=1);

namespace App\Form\Sale;

use App\Entity\StockLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Ligne de vente : article d'occasion du lieu choisi (variante × état), quantité,
 * réduction en % (entier) et/ou prix final unitaire TTC (euros → centimes).
 */
final class SaleLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<StockLevel> $sellable */
        $sellable = $options['sellable'];
        $choices = [];
        foreach ($sellable as $level) {
            $choices[self::label($level)] = self::value($level);
        }

        $builder
            ->add('stock', ChoiceType::class, [
                'label' => 'Article d\'occasion',
                'required' => false,
                'choices' => $choices,
                'placeholder' => [] === $choices ? 'Aucun article' : 'Choisir un article',
                'help' => [] === $choices ? 'Choisissez d\'abord le lieu.' : null,
                'invalid_message' => 'Choisissez un article d\'occasion en stock dans ce lieu.',
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'empty_data' => '1',
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new Range(min: 1, minMessage: 'La quantité doit être au moins 1.')],
            ])
            ->add('discount', IntegerType::class, [
                'label' => 'Réduction (%)',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric'],
                'constraints' => [new Range(min: 0, max: 100, notInRangeMessage: 'La réduction doit être comprise entre 0 et 100 %.')],
            ])
            ->add('finalPrice', MoneyType::class, [
                'label' => 'Prix final unitaire TTC (€)',
                'required' => false,
                'currency' => false,
                'divisor' => 100,
                'input' => 'integer',
                'help' => 'Remplace le prix calculé par la réduction.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('sellable', []);
        $resolver->setAllowedTypes('sellable', 'array');
    }

    /** Valeur d'un choix : « <variantId>-<état> ». */
    public static function value(StockLevel $level): string
    {
        return $level->getVariant()->getId().'-'.$level->getCondition()->value;
    }

    /** « Désignation · taille — Usé (3) ». */
    private static function label(StockLevel $level): string
    {
        $variant = $level->getVariant();

        return \sprintf('%s · %s — %s (%d)', $variant->getProduct()?->getDesignation() ?? '', $variant->getLabel() ?: 'Unique', $level->getCondition()->label(), $level->getQuantity());
    }
}
