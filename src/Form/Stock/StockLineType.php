<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Form\StockVariantAutocompleteField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Ligne de sortie ou de transfert : soit le code d'une pièce (HS-…), soit une variante et une quantité.
 */
final class StockLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('itemCode', TextType::class, [
                'label' => 'Code de la pièce',
                'required' => false,
                'help' => 'Code HS-… d\'une pièce suivie individuellement. Laissez vide pour un article en quantité.',
                'attr' => ['placeholder' => 'HS-000123', 'autocapitalize' => 'characters', 'autocomplete' => 'off'],
            ])
            ->add('variant', StockVariantAutocompleteField::class, [
                'label' => 'Article',
                'movable_only' => true,
                'required' => false,
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'required' => false,
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new GreaterThanOrEqual(1, message: 'La quantité doit être au moins 1.')],
            ]);
    }
}
