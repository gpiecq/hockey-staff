<?php

declare(strict_types=1);

namespace App\Form\Sale;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Ligne de vente : code HS-… de la pièce, réduction en % (entier) et/ou prix final TTC (euros → centimes).
 */
final class SaleLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('itemCode', TextType::class, [
                'label' => 'Code de la pièce',
                'required' => false,
                'help' => 'Code HS-… d\'une pièce d\'occasion en stock.',
                'attr' => ['placeholder' => 'HS-000123', 'autocapitalize' => 'characters', 'autocomplete' => 'off'],
            ])
            ->add('discount', IntegerType::class, [
                'label' => 'Réduction (%)',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric'],
                'constraints' => [new Range(min: 0, max: 100, notInRangeMessage: 'La réduction doit être comprise entre 0 et 100 %.')],
            ])
            ->add('finalPrice', MoneyType::class, [
                'label' => 'Prix final TTC (facultatif)',
                'required' => false,
                'currency' => 'EUR',
                'divisor' => 100,
                'input' => 'integer',
                'help' => 'Remplace le prix calculé par la réduction.',
            ]);
    }
}
