<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Enum\ItemCondition;
use App\Form\StockVariantAutocompleteField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

final class StockEntryLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('variant', StockVariantAutocompleteField::class, [
                'label' => 'Article',
                'required' => true,
                'constraints' => [new NotNull(message: 'Choisissez un article.')],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new NotBlank(message: 'Indiquez la quantité.'), new GreaterThanOrEqual(1, message: 'La quantité doit être au moins 1.')],
            ]);

        if ($options['with_cost']) {
            $builder->add('unitCostHt', MoneyType::class, [
                'label' => 'Coût unitaire HT',
                'currency' => 'EUR',
                'divisor' => 100,
                'required' => false,
                'help' => 'Vide = tarif club HT de la saison',
                'attr' => ['inputmode' => 'decimal'],
            ]);
        }

        $builder
            ->add('condition', EnumType::class, [
                'label' => 'État',
                'class' => ItemCondition::class,
                'choice_label' => static fn (ItemCondition $condition): string => $condition->label(),
            ])
            ->add('serialNumbers', TextareaType::class, [
                'label' => 'Numéros de série',
                'required' => false,
                'help' => 'Un numéro par ligne (articles sérialisés, facultatif).',
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_cost' => true]);
        $resolver->setAllowedTypes('with_cost', 'bool');
    }
}
