<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Location;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotNull;

final class StockEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('location', EntityType::class, [
                'label' => 'Lieu de réception',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un lieu',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')
                    ->where('l.active = true')->orderBy('l.name')->addOrderBy('l.id'),
                'constraints' => [new NotNull(message: 'Choisissez un lieu.')],
            ])
            ->add('documentReference', TextType::class, ['label' => 'Référence du document (BL, facture)', 'required' => false])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('lines', CollectionType::class, [
                'label' => false,
                'entry_type' => StockEntryLineType::class,
                'entry_options' => ['with_cost' => $options['with_cost'], 'label' => false],
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
        $resolver->setDefaults(['with_cost' => true]);
        $resolver->setAllowedTypes('with_cost', 'bool');
    }
}
