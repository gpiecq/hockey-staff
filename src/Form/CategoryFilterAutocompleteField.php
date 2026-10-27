<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Filtre catalogue : choix d'une catégorie à n'importe quel niveau (1 à 3), recherche sur le chemin complet.
 * Suggestions : /autocomplete/category_filter_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class CategoryFilterAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Category::class,
            'choice_label' => 'path',
            'required' => false,
            'placeholder' => 'Toutes les catégories',
            'security' => 'ROLE_VIEWER',
            'max_results' => 20,
            'query_builder' => static fn (CategoryRepository $repository): QueryBuilder => $repository->createTreeQueryBuilder(),
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $term = mb_strtoupper(trim($query));
                if ('' === $term) {
                    return;
                }
                $qb->andWhere("UPPER(CONCAT(COALESCE(g.name, ''), ' ', COALESCE(p.name, ''), ' ', c.name)) LIKE :term")
                    ->setParameter('term', '%'.addcslashes($term, '%_\\').'%');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
