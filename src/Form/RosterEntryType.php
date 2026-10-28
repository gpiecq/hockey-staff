<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/** Ajout d'un joueur à un effectif : formulaire sans entité (données : player, number). */
final class RosterEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('player', PlayerAutocompleteField::class, [
                'label' => 'Joueur',
                'constraints' => [new NotNull(message: 'Le joueur est obligatoire.')],
            ])
            ->add('number', IntegerType::class, [
                'label' => 'N°',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 99, 'inputmode' => 'numeric'],
                'constraints' => [new Range(min: 0, max: 99, notInRangeMessage: 'Le numéro doit être compris entre {{ min }} et {{ max }}.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
