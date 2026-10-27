<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:user:create'));
    }

    public function testCreatesAdmin(): void
    {
        $tester = $this->tester();
        $code = $tester->execute([
            'email' => 'a@b.fr',
            'firstName' => 'Prénom',
            'lastName' => 'Nom',
            'role' => 'ROLE_ADMIN',
            '--password' => 'secret123',
        ]);

        self::assertSame(0, $code);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'a@b.fr']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserRole::Admin, $user->getRole());
        self::assertNotSame('secret123', $user->getPassword());
        self::assertTrue(password_verify('secret123', $user->getPassword()));
    }

    public function testUnknownRoleFails(): void
    {
        $code = $this->tester()->execute([
            'email' => 'a@b.fr', 'firstName' => 'P', 'lastName' => 'N', 'role' => 'ROLE_NOPE', '--password' => 'secret123',
        ]);

        self::assertNotSame(0, $code);
    }

    public function testExistingEmailFails(): void
    {
        $args = ['email' => 'a@b.fr', 'firstName' => 'P', 'lastName' => 'N', 'role' => 'ROLE_VIEWER', '--password' => 'secret123'];
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($args));

        self::assertNotSame(0, $tester->execute($args));
    }
}
