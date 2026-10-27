<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de recherche du catalogue : GET, sans jeton CSRF, noms de champs nus (?q=…&category=…). */
final class CatalogueFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->setMethod('GET')
            ->add('q', SearchType::class, [
                'label' => 'Recherche',
                'required' => false,
                'attr' => ['placeholder' => 'Désignation, référence ou EAN'],
            ])
            ->add('category', CategoryFilterAutocompleteField::class, ['label' => 'Catégorie'])
            ->add('supplier', SupplierFilterAutocompleteField::class, ['label' => 'Fournisseur'])
            ->add('brand', BrandFilterAutocompleteField::class, ['label' => 'Marque'])
            ->add('includeInactive', CheckboxType::class, [
                'label' => 'Inclure les produits retirés du catalogue',
                'required' => false,
            ]);
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
