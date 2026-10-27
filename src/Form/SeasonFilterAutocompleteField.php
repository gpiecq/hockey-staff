<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Choix d'une saison en autocomplétion serveur (recherche sur le libellé, la plus récente d'abord).
 * Suggestions : /autocomplete/season_filter_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class SeasonFilterAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Season::class,
            'choice_label' => 'label',
            'required' => false,
            'placeholder' => 'Toutes les saisons',
            'security' => 'ROLE_VIEWER',
            'max_results' => 20,
            'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('e')->orderBy('e.startsOn', 'DESC'),
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $term = trim($query);
                if ('' === $term) {
                    return;
                }
                $qb->andWhere('e.label LIKE :term')
                    ->setParameter('term', '%'.addcslashes($term, '%_\\').'%');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
