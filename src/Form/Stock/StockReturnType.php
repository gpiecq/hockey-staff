<?php

declare(strict_types=1);

namespace App\Form\Stock;

use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Enum\StockCondition;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;

/** Données : player, quantity, location, condition, comment. Joueurs proposés : les détenteurs de la variante. */
final class StockReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<PlayerHolding> $holdings */
        $holdings = $options['holdings'];
        $held = [];
        foreach ($holdings as $holding) {
            $held[(int) $holding->getPlayer()->getId()] = $holding->getQuantity();
        }

        $builder
            ->add('player', EntityType::class, [
                'label' => 'Joueur',
                'class' => Player::class,
                'choices' => array_map(static fn (PlayerHolding $holding): Player => $holding->getPlayer(), $holdings),
                'choice_label' => static fn (Player $player): string => \sprintf('%s (%d)', $player->getFullName(), $held[(int) $player->getId()] ?? 0),
                'placeholder' => 'Choisir un joueur',
                'constraints' => [new NotNull(message: 'Choisissez un joueur.')],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
                'constraints' => [new NotNull(message: 'Indiquez la quantité.'), new Positive(message: 'La quantité doit être au moins 1.')],
            ]);
        LocationField::add($builder, 'Lieu de retour');
        $builder
            ->add('condition', EnumType::class, [
                'label' => 'Nouvel état',
                'class' => StockCondition::class,
                'expanded' => true,
                'choice_label' => static fn (StockCondition $condition): string => $condition->label(),
                'constraints' => [new NotNull(message: 'Choisissez un état.')],
            ])
            ->add('comment', TextareaType::class, ['label' => 'Commentaire', 'required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['holdings' => []]);
        $resolver->setAllowedTypes('holdings', 'array');
    }
}
