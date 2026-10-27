<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;

final class UserFactory
{
    public static function create(
        EntityManagerInterface $em,
        UserRole $role,
        string $email = 'user@example.test',
        string $password = 'password123',
    ): User {
        $user = new User($email, 'Jean', 'Dupont', $role);
        // bcrypt bas coût : vérifié sans problème par le hasher "auto" de l'application
        $user->setPassword(password_hash($password, \PASSWORD_BCRYPT, ['cost' => 4]));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
