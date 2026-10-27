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
 * Choix d'une catégorie parente (niveaux 1 et 2) en autocomplétion serveur.
 * Suggestions : /autocomplete/parent_category_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class ParentCategoryAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Category::class,
            'choice_label' => 'path',
            'required' => false,
            'placeholder' => 'Aucune (catégorie racine)',
            'security' => 'ROLE_VIEWER',
            'max_results' => 20,
            'query_builder' => static fn (CategoryRepository $repository): QueryBuilder => $repository->createParentCandidatesQueryBuilder(),
            // recherche insensible à la casse sur le chemin (nom + noms des ancêtres)
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $term = mb_strtoupper(trim($query));
                if ('' === $term) {
                    return;
                }
                $qb->leftJoin('p.parent', 'g')
                    ->andWhere("UPPER(CONCAT(COALESCE(g.name, ''), ' ', COALESCE(p.name, ''), ' ', c.name)) LIKE :term")
                    ->setParameter('term', '%'.addcslashes($term, '%_\\').'%');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
