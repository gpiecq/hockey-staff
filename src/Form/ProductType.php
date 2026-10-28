<?php

declare(strict_types=1);

namespace App\Form;

use App\Catalogue\ProductKindResolver;
use App\Entity\Product;
use App\Enum\ProductKind;
use App\Stock\StockPresence;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

final class ProductType extends AbstractType
{
    public function __construct(
        private readonly ProductKindResolver $kinds,
        private readonly StockPresence $stockPresence,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $product = $options['data'] ?? null;
        $locked = $product instanceof Product && $this->stockPresence->productHasStock($product);

        $builder
            ->add('supplier', SupplierFilterAutocompleteField::class, [
                'label' => 'Fournisseur',
                'required' => true,
                'placeholder' => 'Choisir un fournisseur',
            ])
            ->add('reference', TextType::class, ['label' => 'Référence', 'empty_data' => ''])
            ->add('designation', TextType::class, ['label' => 'Désignation', 'empty_data' => ''])
            ->add('brand', BrandFilterAutocompleteField::class, [
                'label' => 'Marque',
                'placeholder' => 'Aucune marque',
            ])
            ->add('category', CategoryFilterAutocompleteField::class, [
                'label' => 'Catégorie',
                'required' => true,
                'placeholder' => 'Choisir une catégorie',
                'constraints' => [new NotNull(message: 'La catégorie est obligatoire.')],
            ])
            // Deux radios non présélectionnés à la création : un choix vide reçoit le défaut de la catégorie (côté serveur).
            ->add('kind', EnumType::class, [
                'label' => 'Type',
                'class' => ProductKind::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'disabled' => $locked,
                'help' => $locked ? 'Le produit a du stock ou du matériel prêté : son type ne peut plus changer.' : null,
                'choice_label' => static fn (ProductKind $kind): string => $kind->label(),
                'getter' => static fn (Product $product): ?ProductKind => null === $product->getId() ? null : $product->getKind(),
                'setter' => function (Product $product, ?ProductKind $kind): void {
                    $category = $product->getCategory();
                    if (null === $kind && null !== $category) {
                        $kind = $this->kinds->defaultFor($category);
                    }
                    if (null !== $kind) {
                        $product->setKind($kind);
                    }
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
