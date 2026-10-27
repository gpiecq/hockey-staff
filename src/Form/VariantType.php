<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Variant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('ean', TextType::class, ['label' => 'Code EAN', 'required' => false]);
        foreach (['size' => 'Taille', 'fit' => 'Coupe', 'side' => 'Côté', 'width' => 'Largeur', 'color' => 'Couleur'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'required' => false]);
        }
        $builder->add('active', CheckboxType::class, ['label' => 'Au catalogue', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Variant::class]);
    }
}
