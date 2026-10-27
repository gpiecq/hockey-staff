<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $admin = UserFactory::create($this->em($client), UserRole::Admin, 'admin@example.test');
        $client->loginUser($admin);

        return $admin;
    }

    public function testManagerIsForbidden(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'manager@example.test'));

        $client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesUsersMenuEntry(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/admin/users"]');
    }

    public function testAdminCreatesUser(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('.tile span')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Admin', 'Manager', 'Responsable matériel', 'Lecture seule'], $labels);
        self::assertCount(4, $crawler->filter('input[type=radio][name="user[role]"]'));

        $form = $crawler->selectButton('Enregistrer')->form([
            'user[email]' => 'nouveau@example.test',
            'user[firstName]' => 'Marie',
            'user[lastName]' => 'Martin',
            'user[role]' => 'ROLE_STOCK',
            'user[plainPassword]' => 'motdepasse1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $user = $this->em($client)->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserRole::Stock, $user->getRole());
        self::assertTrue($user->isEnabled());
        self::assertNotSame('motdepasse1', $user->getPassword());
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        self::assertTrue($hasher->isPasswordValid($user, 'motdepasse1'));
    }

    public function testPasswordRequiredAndMinLengthOnCreate(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Enregistrer')->form([
            'user[email]' => 'court@example.test',
            'user[firstName]' => 'Marie',
            'user[lastName]' => 'Martin',
            'user[role]' => 'ROLE_VIEWER',
            'user[plainPassword]' => 'court',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        UserFactory::create($this->em($client), UserRole::Viewer, 'doublon@example.test');

        $crawler = $client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Enregistrer')->form([
            'user[email]' => 'doublon@example.test',
            'user[firstName]' => 'Marie',
            'user[lastName]' => 'Martin',
            'user[role]' => 'ROLE_VIEWER',
            'user[plainPassword]' => 'motdepasse1',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cet email est déjà utilisé.');
    }

    public function testAdminEditsRoleAndActivationWithoutPassword(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $target = UserFactory::create($this->em($client), UserRole::Viewer, 'cible@example.test');
        $oldHash = $target->getPassword();

        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form([
            'user[role]' => 'ROLE_MANAGER',
            'user[enabled]' => false,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');
        $em = $this->em($client);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserRole::Manager, $reloaded->getRole());
        self::assertFalse($reloaded->isEnabled());
        self::assertSame($oldHash, $reloaded->getPassword());
    }

    public function testAdminCannotDeactivateSelf(): void
    {
        $client = static::createClient();
        $admin = $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/users/'.$admin->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form(['user[enabled]' => false]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Vous ne pouvez pas désactiver votre propre compte.');
    }

    public function testAdminCannotRemoveOwnAdminRole(): void
    {
        $client = static::createClient();
        $admin = $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/users/'.$admin->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form(['user[role]' => 'ROLE_MANAGER']);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Vous ne pouvez pas retirer votre propre rôle Admin.');
    }

    public function testListIsPaginated(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $this->loginAdmin($client);
        for ($i = 1; $i <= 34; ++$i) {
            UserFactory::create($em, UserRole::Viewer, \sprintf('user%02d@example.test', $i));
        }

        $client->request('GET', '/admin/users');
        self::assertSelectorTextContains('.pagination-count', '1–30 sur 35 utilisateurs');
        self::assertSelectorExists('.pagination a[aria-label="Page suivante"]');

        $crawler = $client->request('GET', '/admin/users?page=2');
        self::assertSelectorTextContains('.pagination-count', '31–35 sur 35 utilisateurs');
        self::assertCount(5, $crawler->filter('tbody tr'));
        self::assertSelectorExists('.pagination [aria-current="page"]');

        $client->request('GET', '/admin/users?page=999');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination-count', '31–35 sur 35 utilisateurs');

        $client->request('GET', '/admin/users?page=abc');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination-count', '1–30 sur 35 utilisateurs');
    }

    public function testPaginationKeepsQueryParams(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $this->loginAdmin($client);
        for ($i = 1; $i <= 34; ++$i) {
            UserFactory::create($em, UserRole::Viewer, \sprintf('user%02d@example.test', $i));
        }

        $crawler = $client->request('GET', '/admin/users?foo=bar');
        $href = $crawler->filter('.pagination a[aria-label="Page suivante"]')->attr('href');
        self::assertIsString($href);
        self::assertStringContainsString('foo=bar', $href);
        self::assertStringContainsString('page=2', $href);
    }
}
