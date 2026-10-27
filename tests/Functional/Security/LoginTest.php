<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class LoginTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password, bool $remember = false): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        if ($remember) {
            $field = $form['_remember_me'];
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $field->tick();
        }
        $client->submit($form);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testValidCredentialsReachDashboard(): void
    {
        $client = static::createClient();
        UserFactory::create($this->em($client), UserRole::Viewer);

        $this->submitLogin($client, 'user@example.test', 'password123');
        self::assertResponseRedirects('/');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Jean Dupont');
    }

    public function testInvalidPasswordShowsError(): void
    {
        $client = static::createClient();
        UserFactory::create($this->em($client), UserRole::Viewer);

        $this->submitLogin($client, 'user@example.test', 'mauvais');
        $client->followRedirect();

        self::assertSelectorTextContains('.flash-error, [role="alert"]', 'Identifiants invalides.');
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $user = UserFactory::create($em, UserRole::Viewer);
        $user->setEnabled(false);
        $em->flush();

        $this->submitLogin($client, 'user@example.test', 'password123');
        $client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Compte désactivé.');
    }

    public function testRememberMeCookieIsSet(): void
    {
        $client = static::createClient();
        UserFactory::create($this->em($client), UserRole::Viewer);

        $this->submitLogin($client, 'user@example.test', 'password123', true);

        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));
    }

    public function testRoleHierarchy(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $this->em($client);
        $admin = UserFactory::create($em, UserRole::Admin, 'admin@example.test');
        $stock = UserFactory::create($em, UserRole::Stock, 'stock@example.test');

        /** @var TokenStorageInterface $storage */
        $storage = $container->get('security.token_storage');
        /** @var AuthorizationCheckerInterface $checker */
        $checker = $container->get('security.authorization_checker');

        $storage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        self::assertTrue($checker->isGranted('ROLE_VIEWER'));
        self::assertTrue($checker->isGranted('ROLE_STOCK'));

        $storage->setToken(new UsernamePasswordToken($stock, 'main', $stock->getRoles()));
        self::assertFalse($checker->isGranted('ROLE_MANAGER'));
    }
}
