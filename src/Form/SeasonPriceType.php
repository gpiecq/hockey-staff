<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SeasonPrice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SeasonPriceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['clubPriceTtc' => 'Prix club TTC', 'publicPriceTtc' => 'Prix public TTC'] as $field => $label) {
            $builder->add($field, MoneyType::class, [
                'label' => $label,
                'required' => false,
                'currency' => 'EUR',
                'divisor' => 100,
                'input' => 'integer',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeasonPrice::class]);
    }
}
