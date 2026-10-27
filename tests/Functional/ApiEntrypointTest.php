<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiEntrypointTest extends WebTestCase
{
    public function testApiEntrypointIsReachable(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $client->loginUser(UserFactory::create($em, UserRole::Viewer));
        $client->request('GET', '/api', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
    }
}
