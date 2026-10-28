<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Item;
use App\Enum\ItemCondition;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** Données : item, location, condition, comment. */
final class StockReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('item', EntityType::class, [
            'label' => 'Pièce prêtée',
            'class' => Item::class,
            'choices' => $options['loaned'],
            'choice_label' => static fn (Item $item): string => $item->getCode().' — '.($item->getHolder()?->getFullName() ?? '?'),
            'placeholder' => 'Choisir une pièce',
            'constraints' => [new NotNull(message: 'Choisissez une pièce.')],
        ]);
        LocationField::add($builder, 'Lieu de retour');
        $builder
            ->add('condition', EnumType::class, [
                'label' => 'Nouvel état',
                'class' => ItemCondition::class,
                'expanded' => true,
                'choice_label' => static fn (ItemCondition $condition): string => $condition->label(),
                'constraints' => [new NotNull(message: 'Choisissez un état.')],
            ])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['loaned' => []]);
        $resolver->setAllowedTypes('loaned', 'array');
    }
}
