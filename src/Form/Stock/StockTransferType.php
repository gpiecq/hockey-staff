<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Location;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotNull;

final class StockTransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', EntityType::class, [
                'label' => 'Lieu d\'origine',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un lieu',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')->orderBy('l.name')->addOrderBy('l.id'),
                'constraints' => [new NotNull(message: 'Choisissez un lieu d\'origine.')],
            ])
            ->add('to', EntityType::class, [
                'label' => 'Lieu de destination',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un lieu',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')
                    ->where('l.active = true')->orderBy('l.name')->addOrderBy('l.id'),
                'constraints' => [new NotNull(message: 'Choisissez un lieu de destination.')],
            ])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('lines', CollectionType::class, [
                'label' => false,
                'entry_type' => StockLineType::class,
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
