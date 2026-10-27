<?php

declare(strict_types=1);

namespace App\Form\Stock;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Collection de lignes de sortie ou de transfert (ajout et retrait dynamiques).
 */
final class StockLinesType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => false,
            'entry_type' => StockLineType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'delete_empty' => false,
            'prototype' => true,
            'error_bubbling' => false,
            'constraints' => [new Count(min: 1, minMessage: 'Ajoutez au moins une ligne.')],
        ]);
    }

    public function getParent(): string
    {
        return CollectionType::class;
    }
}
