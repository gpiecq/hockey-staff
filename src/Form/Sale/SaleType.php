<?php

declare(strict_types=1);

namespace App\Form\Sale;

use App\Form\PlayerAutocompleteField;
use App\Form\Stock\LocationField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Données : location, buyer, player, comment, lines. Option `sellable` : occasion en stock au lieu choisi
 * (list<StockLevel>), proposée sur chaque ligne ; le choix du lieu recharge le formulaire.
 */
final class SaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        LocationField::add($builder, 'Lieu');
        $builder
            ->add('buyer', TextareaType::class, [
                'label' => 'Acheteur (nom, contact)',
                'attr' => ['rows' => 2],
                'constraints' => [new NotBlank(message: 'Indiquez l\'acheteur.')],
            ])
            ->add('player', PlayerAutocompleteField::class, ['label' => 'Joueur (facultatif)', 'required' => false])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('lines', CollectionType::class, [
                'label' => false,
                'entry_type' => SaleLineType::class,
                'entry_options' => ['label' => false, 'sellable' => $options['sellable']],
                'allow_add' => true,
                'allow_delete' => true,
                'delete_empty' => false,
                'prototype' => true,
                'error_bubbling' => false,
                'constraints' => [new Count(min: 1, minMessage: 'Ajoutez au moins une ligne.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('sellable', []);
        $resolver->setAllowedTypes('sellable', 'array');
    }
}
