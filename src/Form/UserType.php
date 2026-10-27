<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')];
        if ($options['password_required']) {
            array_unshift($passwordConstraints, new NotBlank(message: 'Le mot de passe est obligatoire.'));
        }

        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'L\'adresse e-mail est obligatoire.')],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Le prénom est obligatoire.')],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Le nom est obligatoire.')],
            ])
            ->add('role', EnumType::class, [
                'label' => 'Rôle',
                'class' => UserRole::class,
                'expanded' => true,
                'choice_label' => static fn (UserRole $role): string => $role->label(),
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Compte actif',
                'required' => false,
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $options['password_required'] ? 'Mot de passe' : 'Nouveau mot de passe',
                'help' => $options['password_required'] ? null : 'Laisser vide pour ne pas le modifier.',
                'mapped' => false,
                'required' => $options['password_required'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $passwordConstraints,
            ]);

        if ($options['editing_self']) {
            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $form = $event->getForm();
                if (UserRole::Admin !== $form->get('role')->getData()) {
                    $form->get('role')->addError(new FormError('Vous ne pouvez pas retirer votre propre rôle Admin.'));
                }
                if (true !== $form->get('enabled')->getData()) {
                    $form->get('enabled')->addError(new FormError('Vous ne pouvez pas désactiver votre propre compte.'));
                }
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'password_required' => false,
            'editing_self' => false,
        ]);
        $resolver->setAllowedTypes('password_required', 'bool');
        $resolver->setAllowedTypes('editing_self', 'bool');
    }
}
