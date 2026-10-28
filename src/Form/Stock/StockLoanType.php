<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Form\PlayerAutocompleteField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;

/** Données : location, player, condition, quantity, comment. */
final class StockLoanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $counts */
        $counts = $options['counts'];
        LocationField::add($builder, 'Lieu');
        $builder
            ->add('player', PlayerAutocompleteField::class, [
                'label' => 'Joueur',
                'constraints' => [new NotNull(message: 'Choisissez un joueur.')],
            ])
            ->add('condition', EnumType::class, ConditionChoices::options('État', $counts) + [
                'constraints' => [new NotNull(message: 'Choisissez un état.')],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new NotNull(message: 'Indiquez la quantité.'), new Positive(message: 'La quantité doit être au moins 1.')],
            ])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['counts' => []]);
        $resolver->setAllowedTypes('counts', 'array');
    }
}
