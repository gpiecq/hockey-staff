<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Team;
use App\Form\PlayerAutocompleteField;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;

/** Données : location, recipient (player|team), player, team, quantity | condition, comment. */
final class StockGiftType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $counts */
        $counts = $options['counts'];
        LocationField::add($builder, 'Lieu');
        $builder
            ->add('recipient', ChoiceType::class, [
                'label' => 'Bénéficiaire',
                'choices' => ['Joueur' => 'player', 'Équipe' => 'team'],
                'expanded' => true,
                'constraints' => [new NotNull(message: 'Choisissez un bénéficiaire.')],
            ])
            ->add('player', PlayerAutocompleteField::class, ['label' => 'Joueur', 'required' => false])
            ->add('team', EntityType::class, [
                'label' => 'Équipe',
                'class' => Team::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Choisir une équipe',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('t')->orderBy('t.name')->addOrderBy('t.id'),
            ]);
        if ($options['equipment']) {
            $builder->add('condition', EnumType::class, ConditionChoices::options('État', $counts) + [
                'constraints' => [new NotNull(message: 'Choisissez un état.')],
            ]);
        }
        $builder->add('quantity', IntegerType::class, [
            'label' => 'Quantité',
            'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            'constraints' => [new NotNull(message: 'Indiquez la quantité.'), new Positive(message: 'La quantité doit être au moins 1.')],
        ]);
        $builder->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['counts' => [], 'equipment' => true]);
        $resolver->setAllowedTypes('counts', 'array');
        $resolver->setAllowedTypes('equipment', 'bool');
    }
}
