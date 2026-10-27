<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Location;
use App\Enum\StockMovementType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Filtres de l'historique : GET, sans jeton CSRF, noms de champs nus (?type=…&lieu=…&du=…&au=…&variante=…). */
final class OperationFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->setMethod('GET')
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => StockMovementType::class,
                'required' => false,
                'placeholder' => 'Tous les types',
                'choice_label' => static fn (StockMovementType $type): string => $type->label(),
            ])
            ->add('lieu', EntityType::class, [
                'label' => 'Lieu',
                'class' => Location::class,
                'required' => false,
                'choice_label' => 'name',
                'placeholder' => 'Tous les lieux',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('l')->orderBy('l.name')->addOrderBy('l.id'),
            ])
            ->add('du', DateType::class, ['label' => 'Du', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('au', DateType::class, ['label' => 'Au', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('variante', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'method' => 'GET',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
