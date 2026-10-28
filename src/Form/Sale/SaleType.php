<?php

declare(strict_types=1);

namespace App\Form\Sale;

use App\Entity\Location;
use App\Form\PlayerAutocompleteField;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/** Données : location, buyer, player, comment, lines. */
final class SaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('location', EntityType::class, [
                'label' => 'Lieu',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un lieu',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')->where('l.active = true')->orderBy('l.name')->addOrderBy('l.id'),
                'constraints' => [new NotNull(message: 'Choisissez un lieu.')],
            ])
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
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'delete_empty' => false,
                'prototype' => true,
                'error_bubbling' => false,
                'constraints' => [new Count(min: 1, minMessage: 'Ajoutez au moins une ligne.')],
            ]);
    }
}
