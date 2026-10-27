<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Supplier;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Filtre catalogue : choix d'un fournisseur en autocomplétion serveur (recherche insensible à la casse sur le nom).
 * Suggestions : /autocomplete/supplier_filter_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class SupplierFilterAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Supplier::class,
            'choice_label' => 'name',
            'required' => false,
            'placeholder' => 'Tous les fournisseurs',
            'security' => 'ROLE_VIEWER',
            'max_results' => 20,
            'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('e')->orderBy('e.name'),
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $term = mb_strtoupper(trim($query));
                if ('' === $term) {
                    return;
                }
                $qb->andWhere('UPPER(e.name) LIKE :term')
                    ->setParameter('term', '%'.addcslashes($term, '%_\\').'%');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
