<?php

declare(strict_types=1);

namespace App\Tests\Functional\Player;

use App\Enum\UserRole;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerAutocompleteTest extends WebTestCase
{
    private const string URL = '/autocomplete/player_autocomplete_field';

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * @return list<string>
     */
    private function texts(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_array($data['results']));

        /** @var list<string> $texts */
        $texts = array_column($data['results'], 'text');

        return $texts;
    }

    public function testSaleFormPlayerFieldPointsToTheEndpoint(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'u@example.test'));

        $crawler = $client->request('GET', '/ventes/new');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::URL, (string) $crawler->filter('select[name="sale[player]"]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));
    }

    public function testSuggestionsAreAccentInsensitiveAndSkipDeletedPlayers(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Viewer, 'u@example.test'));
        PlayerFixtures::player($em, 'Martin', 'Léo');
        PlayerFixtures::player($em, 'Léonard', 'Hugo');
        PlayerFixtures::player($em, 'Durand', 'Paul');
        $deleted = PlayerFixtures::player($em, 'Bernard', 'Léon');
        $em->remove($deleted);
        $em->flush();

        $client->request('GET', self::URL, ['query' => 'leo']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Hugo Léonard', 'Léo Martin'], $this->texts($client));
    }

    public function testSuggestionsRequireLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL, ['query' => 'leo']);

        self::assertResponseRedirects();
    }
}
