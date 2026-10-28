<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Player;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Choix d'un joueur (non supprimé) par nom ou prénom, insensible aux accents.
 * Suggestions : /autocomplete/player_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class PlayerAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Player::class,
            'choice_label' => static fn (Player $player): string => $player->getFullName(),
            'placeholder' => 'Nom ou prénom du joueur',
            'security' => 'ROLE_VIEWER',
            'max_results' => 20,
            'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('p')
                ->orderBy('p.lastName')
                ->addOrderBy('p.firstName')
                ->addOrderBy('p.id'),
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $query = trim($query);
                if ('' === $query) {
                    return;
                }
                $qb->andWhere('LOWER(UNACCENT(p.lastName)) LIKE LOWER(UNACCENT(:like)) OR LOWER(UNACCENT(p.firstName)) LIKE LOWER(UNACCENT(:like))')
                    ->setParameter('like', '%'.addcslashes($query, '%_\\').'%');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
