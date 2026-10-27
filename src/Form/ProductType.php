<?php

declare(strict_types=1);

namespace App\Form;

use App\Catalogue\TrackingModeResolver;
use App\Entity\Product;
use App\Enum\TrackingMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

final class ProductType extends AbstractType
{
    public function __construct(private readonly TrackingModeResolver $trackingModes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            ->add('trackingMode', EnumType::class, [
                'label' => 'Mode de suivi',
                'class' => TrackingMode::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (TrackingMode $mode): string => match ($mode) {
                    TrackingMode::Serialized => 'Sérialisé',
                    TrackingMode::Quantity => 'Quantité',
                },
                'getter' => static fn (Product $product): ?TrackingMode => null === $product->getId() ? null : $product->getTrackingMode(),
                'setter' => function (Product $product, ?TrackingMode $mode): void {
                    $category = $product->getCategory();
                    if (null === $mode && null !== $category) {
                        $mode = $this->trackingModes->defaultFor($category);
                    }
                    if (null !== $mode) {
                        $product->setTrackingMode($mode);
                    }
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
