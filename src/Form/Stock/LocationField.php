<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Location;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

final class LocationField
{
    public static function add(FormBuilderInterface $builder, string $label): void
    {
        $builder->add('location', EntityType::class, [
            'label' => $label,
            'class' => Location::class,
            'choice_label' => 'name',
            'placeholder' => 'Choisir un lieu',
            'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')->where('l.active = true')->orderBy('l.name')->addOrderBy('l.id'),
            'constraints' => [new NotNull(message: 'Choisissez un lieu.')],
            'attr' => ['data-action' => 'variant-location#change'],
        ]);
    }
}
