<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Dépôt d'un catalogue fournisseur : fournisseur, saison (autocomplétion) et fichier .xlsx de 20 Mo maximum. */
final class ImportUploadType extends AbstractType
{
    public const string XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier', SupplierFilterAutocompleteField::class, [
                'label' => 'Fournisseur',
                'required' => true,
                'placeholder' => 'Choisir un fournisseur',
                'security' => 'ROLE_MANAGER',
                'constraints' => [new NotBlank(message: 'Le fournisseur est obligatoire.')],
            ])
            ->add('season', SeasonFilterAutocompleteField::class, [
                'label' => 'Saison',
                'required' => true,
                'placeholder' => 'Choisir une saison',
                'security' => 'ROLE_MANAGER',
                'constraints' => [new NotBlank(message: 'La saison est obligatoire.')],
            ])
            ->add('file', FileType::class, [
                'label' => 'Fichier Excel (.xlsx)',
                'help' => 'Un fichier .xlsx de 20 Mo maximum.',
                'attr' => ['accept' => '.xlsx,'.self::XLSX_MIME],
                'constraints' => [
                    new NotBlank(message: 'Le fichier est obligatoire.'),
                    new File(
                        maxSize: '20M',
                        mimeTypes: [self::XLSX_MIME],
                        maxSizeMessage: 'Le fichier est trop volumineux ({{ size }} {{ suffix }}) : 20 Mo maximum.',
                        mimeTypesMessage: 'Le fichier doit être un classeur Excel au format .xlsx.',
                        extensions: ['xlsx'],
                        extensionsMessage: 'Le fichier doit être un classeur Excel au format .xlsx.',
                    ),
                ],
            ]);
    }
}
