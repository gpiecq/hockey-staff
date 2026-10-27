<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/** Tarif appliqué aux variantes sans prix d'un produit : mêmes types et unités (centimes) que SeasonPriceType. */
final class ProductPriceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $money = ['currency' => 'EUR', 'divisor' => 100, 'input' => 'integer'];
        $builder
            ->add('clubPriceTtc', MoneyType::class, $money + [
                'label' => 'Prix club TTC',
                'constraints' => [
                    new NotNull(message: 'Le prix club est obligatoire.'),
                    new PositiveOrZero(message: 'Le prix doit être positif.'),
                ],
            ])
            ->add('publicPriceTtc', MoneyType::class, $money + [
                'label' => 'Prix public TTC',
                'required' => false,
                'constraints' => [new PositiveOrZero(message: 'Le prix doit être positif.')],
            ]);
    }
}
