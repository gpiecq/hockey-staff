<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NativeAppTest extends WebTestCase
{
    private const NATIVE_UA = 'Mozilla/5.0 (iPhone) Hotwire Native iOS; Turbo Native iOS; bridge-components: [barcode-scanner]';

    public function testBrowserKeepsWebNavigation(): void
    {
        $client = $this->clientAs(UserRole::Viewer);
        $crawler = $client->request('GET', '/');

        self::assertCount(0, $crawler->filter('body.native'));
        foreach (['nav.sidebar', 'nav.bottombar', '.mobile-header', '.topbar'] as $selector) {
            self::assertCount(1, $crawler->filter($selector), $selector);
        }
    }

    public function testNativeHidesWebNavigation(): void
    {
        $client = $this->clientAs(UserRole::Viewer);
        $crawler = $client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::NATIVE_UA]);

        self::assertCount(1, $crawler->filter('body.native'));
        foreach (['nav.sidebar', 'nav.bottombar', '.mobile-header', '.topbar'] as $selector) {
            self::assertCount(0, $crawler->filter($selector), $selector);
        }
        self::assertSame('Tableau de bord · Corsaires · Matériel', trim($crawler->filter('title')->text()));
        self::assertCount(1, $crawler->filter('main.content'));
    }

    public function testMenuListsGroupedLinksAndLogout(): void
    {
        $client = $this->clientAs(UserRole::Stock);
        $crawler = $client->request('GET', '/menu')->filter('main.content');

        self::assertResponseIsSuccessful();
        self::assertSame('Menu', trim($crawler->filter('h1')->text()));
        self::assertSame('/scan', $crawler->selectLink('Scanner')->attr('href'));
        self::assertCount(1, $crawler->selectLink('Opérations'));
        self::assertCount(1, $crawler->selectLink('Prêts en cours'));
        self::assertSame('/logout', $crawler->selectLink('Déconnexion')->attr('href'));
        self::assertCount(0, $crawler->selectLink('Utilisateurs'));
    }

    public function testMenuShowsAdminLinksForAdmin(): void
    {
        $client = $this->clientAs(UserRole::Admin);
        $crawler = $client->request('GET', '/menu')->filter('main.content');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectLink('Utilisateurs'));
    }

    private function clientAs(UserRole $role): KernelBrowser
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $client->loginUser(UserFactory::create($em, $role));

        return $client;
    }
}
