<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LayoutTest extends WebTestCase
{
    public function testAuthenticatedLayout(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $user = UserFactory::create($em, UserRole::Viewer);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('· Corsaires · Matériel', trim($crawler->filter('title')->text()));
        self::assertCount(1, $crawler->filter('link[rel="icon"][href*="favicon.ico"]'));
        self::assertCount(1, $crawler->filter('link[rel="apple-touch-icon"]'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('img[src*="logo-corsaires"]')->count());
        self::assertSame('Tableau de bord', trim($crawler->filter('nav [aria-current="page"]')->first()->text()));
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));

        // /favicon.ico est servi directement par FrankenPHP (fichier statique de public/), pas par le noyau.
        self::assertFileExists(\dirname(__DIR__, 2).'/public/favicon.ico');
        self::assertGreaterThan(0, filesize(\dirname(__DIR__, 2).'/public/favicon.ico'));
    }

    public function testLoginPageBranding(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Corsaires · Matériel', $crawler->filter('body')->text());
        self::assertGreaterThanOrEqual(1, $crawler->filter('img[src*="logo-corsaires"]')->count());
    }
}
