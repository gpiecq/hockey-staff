<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SeasonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'help' => 'Format AAAA-AAAA, par exemple 2026-2027.',
                'empty_data' => '',
                'attr' => ['placeholder' => '2026-2027'],
            ])
            ->add('startsOn', DateType::class, [
                'label' => 'Début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'La date de début est invalide.',
                'constraints' => [new NotBlank(message: 'La date de début est obligatoire.')],
                // une date vide est refusée par la contrainte, sans écraser la propriété non nullable
                'setter' => static function (Season $season, ?\DateTimeImmutable $date): void {
                    if (null !== $date) {
                        $season->setStartsOn($date);
                    }
                },
            ])
            ->add('endsOn', DateType::class, [
                'label' => 'Fin',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'La date de fin est invalide.',
                'constraints' => [new NotBlank(message: 'La date de fin est obligatoire.')],
                // une date vide est refusée par la contrainte, sans écraser la propriété non nullable
                'setter' => static function (Season $season, ?\DateTimeImmutable $date): void {
                    if (null !== $date) {
                        $season->setEndsOn($date);
                    }
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Season::class]);
    }
}
