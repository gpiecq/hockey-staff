<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiEntrypointTest extends WebTestCase
{
    public function testApiEntrypointIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
    }
}
