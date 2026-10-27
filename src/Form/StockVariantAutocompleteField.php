<?php

declare(strict_types=1);

namespace App\Form;

use App\Catalogue\Ean;
use App\Entity\Variant;
use App\Stock\EntryCostProvider;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Choix d'une variante à mouvementer (active, non archivée) par référence, désignation ou EAN.
 * Suggestions : /autocomplete/stock_variant_autocomplete_field?query=….
 */
#[AsEntityAutocompleteField]
final class StockVariantAutocompleteField extends AbstractType
{
    public function __construct(private readonly EntryCostProvider $costs)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Variant::class,
            'choice_label' => fn (Variant $variant): string => $this->label($variant),
            'placeholder' => 'Référence, désignation ou EAN',
            'security' => 'ROLE_STOCK',
            'max_results' => 20,
            'movable_only' => false,
            'query_builder' => static fn (Options $options): \Closure => static function (EntityRepository $repository) use ($options): QueryBuilder {
                $qb = $repository->createQueryBuilder('v')
                    ->join('v.product', 'p')
                    ->orderBy('p.reference')
                    ->addOrderBy('v.id');
                // Sortie / transfert : un article retiré du catalogue reste déplaçable, donc pas de filtre actif/archivé.
                if (!$options['movable_only']) {
                    $qb->andWhere('v.active = true')->andWhere('v.archivedAt IS NULL');
                }

                return $qb;
            },
            'filter_query' => static function (QueryBuilder $qb, string $query, EntityRepository $repository): void {
                $query = trim($query);
                if ('' === $query) {
                    return;
                }
                $qb->andWhere('LOWER(UNACCENT(p.designation)) LIKE LOWER(UNACCENT(:like)) OR LOWER(p.reference) LIKE LOWER(:like) OR v.ean IN (:eans)')
                    ->setParameter('like', '%'.addcslashes($query, '%_\\').'%')
                    ->setParameter('eans', Ean::candidates($query));
            },
        ]);
        $resolver->setAllowedTypes('movable_only', 'bool');
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    private function label(Variant $variant): string
    {
        $product = $variant->getProduct();
        $parts = [$product?->getReference(), $product?->getDesignation()];
        if ('' !== $variant->getLabel()) {
            $parts[] = $variant->getLabel();
        }
        $label = implode(' — ', array_filter($parts, static fn (?string $part): bool => null !== $part && '' !== $part));
        if (null !== $variant->getEan()) {
            $label .= ' ('.$variant->getEan().')';
        }
        $club = $this->costs->clubPriceHt($variant);
        if (null !== $club) {
            $label .= ' · tarif club HT '.number_format($club / 100, 2, ',', "\u{202F}").' €';
        }

        return $label;
    }
}
