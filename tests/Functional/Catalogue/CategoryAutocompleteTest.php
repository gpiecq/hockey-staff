<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Category;
use App\Enum\UserRole;
use App\Repository\CategoryRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategoryAutocompleteTest extends WebTestCase
{
    private function login(KernelBrowser $client, UserRole $role): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $client->loginUser(UserFactory::create($em, $role, 'u@example.test'));
    }

    /**
     * @return list<array{value: string, text: string}>
     */
    private function results(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_array($data['results']));

        /** @var list<array{value: string, text: string}> $results */
        $results = $data['results'];

        return $results;
    }

    private function seed(KernelBrowser $client): void
    {
        $repo = $client->getContainer()->get(CategoryRepository::class);
        \assert($repo instanceof CategoryRepository);
        $repo->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $repo->findOrCreatePath(['EQUIPEMENT', 'GARDIEN']);
        $repo->findOrCreatePath(['EQUIPEMENT', 'JOUEUR', 'PATIN']);
        $repo->findOrCreatePath(['CONSOMMABLE']);
    }

    public function testParentFieldIsServerSideAutocomplete(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/categories/new');

        self::assertResponseIsSuccessful();
        $field = $crawler->filter('select[name="category[parent]"]');
        self::assertCount(1, $field);
        self::assertStringContainsString('autocomplete', (string) $field->attr('data-controller'));
        self::assertStringContainsString('/autocomplete/', (string) $field->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));
        self::assertSame('Catégorie parente', trim($crawler->filter('label[for="category_parent"]')->text()));
    }

    public function testSuggestionsUrlReturnsMatchingPathsCaseInsensitively(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->seed($client);

        $crawler = $client->request('GET', '/categories/new');
        $url = (string) $crawler->filter('select[name="category[parent]"]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value');
        // niveau 3 (PATIN) non proposé comme parent ; « pat » ne correspond qu'à lui : on cherche donc « joueur »
        $client->request('GET', $url, ['query' => 'joueur']);

        self::assertResponseIsSuccessful();
        $results = $this->results($client);
        $texts = array_column($results, 'text');
        self::assertSame(['EQUIPEMENT › JOUEUR'], $texts);
        self::assertArrayHasKey('value', $results[0]);
    }

    public function testSuggestionsMatchAncestorNamesAndExcludeOthers(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->seed($client);

        $crawler = $client->request('GET', '/categories/new');
        $url = (string) $crawler->filter('select[name="category[parent]"]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value');
        $client->request('GET', $url, ['query' => 'equi']);

        $results = $this->results($client);
        $texts = array_column($results, 'text');
        sort($texts);
        self::assertSame(['EQUIPEMENT', 'EQUIPEMENT › GARDIEN', 'EQUIPEMENT › JOUEUR'], $texts);
    }

    public function testSuggestionsRequireLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/autocomplete/category_autocomplete_field', ['query' => 'a']);

        self::assertResponseRedirects();
    }

    public function testSubmitWithoutJsWithExistingCategoryId(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->seed($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $parent = $em->getRepository(Category::class)->findOneBy(['name' => 'JOUEUR']);
        self::assertInstanceOf(Category::class, $parent);

        $form = $client->request('GET', '/categories/new')->selectButton('Enregistrer')->form(['category[name]' => 'gants']);
        $form->disableValidation()['category[parent]'] = (string) $parent->getId();
        $client->submit($form);

        self::assertResponseRedirects('/categories');
        $em->clear();
        $created = $em->getRepository(Category::class)->findOneBy(['name' => 'GANTS']);
        self::assertSame('EQUIPEMENT › JOUEUR › GANTS', $created?->getPath());
    }
}
