<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Crée un compte utilisateur (Admin, Manager, Responsable matériel, Lecture seule)')]
final class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Adresse e-mail (identifiant de connexion)')]
        string $email,
        #[Argument(description: 'Prénom', name: 'firstName')]
        string $firstName,
        #[Argument(description: 'Nom', name: 'lastName')]
        string $lastName,
        #[Argument(description: 'Rôle : ROLE_ADMIN, ROLE_MANAGER, ROLE_STOCK ou ROLE_VIEWER')]
        string $role,
        #[Option(description: 'Mot de passe (demandé de façon masquée s\'il est absent)')]
        ?string $password = null,
    ): int {
        $userRole = UserRole::tryFrom($role);
        if (null === $userRole) {
            $io->error(\sprintf('Rôle inconnu "%s". Valeurs possibles : %s.', $role, implode(', ', array_column(UserRole::cases(), 'value'))));

            return Command::FAILURE;
        }

        if (null !== $this->users->findOneBy(['email' => $email])) {
            $io->error(\sprintf('Un utilisateur avec l\'e-mail "%s" existe déjà.', $email));

            return Command::FAILURE;
        }

        if (null === $password || '' === $password) {
            $asked = $io->askHidden('Mot de passe');
            $password = \is_string($asked) ? $asked : null;
        }
        if (null === $password || '' === $password) {
            $io->error('Le mot de passe est obligatoire.');

            return Command::FAILURE;
        }

        $user = new User($email, $firstName, $lastName, $userRole);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Utilisateur %s créé (%s).', $email, $userRole->label()));

        return Command::SUCCESS;
    }
}
