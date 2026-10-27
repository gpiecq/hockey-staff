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

final class CategoryControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function path(KernelBrowser $client, string ...$names): Category
    {
        $repo = $client->getContainer()->get(CategoryRepository::class);
        \assert($repo instanceof CategoryRepository);

        return $repo->findOrCreatePath(array_values($names));
    }

    private function login(KernelBrowser $client, UserRole $role): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    public function testViewerSeesPathsInOrderButNoActions(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $this->path($client, 'EQUIPEMENT', 'JOUEUR', 'PATIN');
        $this->path($client, 'CONSOMMABLE');

        $crawler = $client->request('GET', '/categories');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/categories"]');
        self::assertSelectorNotExists('a[href="/categories/new"]');
        self::assertSelectorNotExists('tbody a');
        $paths = $crawler->filter('tbody tr td:first-child')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['CONSOMMABLE', 'EQUIPEMENT', 'EQUIPEMENT › JOUEUR', 'EQUIPEMENT › JOUEUR › PATIN'], $paths);
    }

    public function testListIsPaginated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        for ($i = 1; $i <= 35; ++$i) {
            $this->path($client, \sprintf('C%02d', $i));
        }

        self::assertCount(30, $client->request('GET', '/categories')->filter('tbody tr'));
        self::assertCount(5, $client->request('GET', '/categories?page=2')->filter('tbody tr'));
    }

    public function testStockCannotWrite(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $category = $this->path($client, 'A');

        $client->request('GET', '/categories/new');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/categories/'.$category->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCreatesRootAndChild(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/categories/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['category[name]' => ' equipement ']));
        self::assertResponseRedirects('/categories');

        $root = $this->em($client)->getRepository(Category::class)->findOneBy(['name' => 'EQUIPEMENT']);
        self::assertInstanceOf(Category::class, $root);

        $form = $client->request('GET', '/categories/new')->selectButton('Enregistrer')->form(['category[name]' => 'joueur']);
        $form->disableValidation()['category[parent]'] = (string) $root->getId();
        $client->submit($form);
        self::assertResponseRedirects('/categories');
        $this->em($client)->clear();
        $child = $this->em($client)->getRepository(Category::class)->findOneBy(['name' => 'JOUEUR']);
        self::assertSame('EQUIPEMENT › JOUEUR', $child?->getPath());
    }

    public function testDuplicateRootRendersErrorWith422(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->path($client, 'EQUIPEMENT');

        $crawler = $client->request('GET', '/categories/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['category[name]' => 'equipement']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce nom est déjà utilisé à ce niveau.');
    }

    public function testLevelThreeCategoryIsNotAcceptedAsParent(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $leaf = $this->path($client, 'A', 'B', 'C');

        $form = $client->request('GET', '/categories/new')->selectButton('Enregistrer')->form(['category[name]' => 'D']);
        $form->disableValidation()['category[parent]'] = (string) $leaf->getId();
        $client->submit($form);

        // le niveau 3 n'est pas un parent proposé : le choix est refusé comme valeur invalide
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
    }

    public function testManagerRenames(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $category = $this->path($client, 'A');

        $crawler = $client->request('GET', '/categories/'.$category->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['category[name]' => 'b']));

        self::assertResponseRedirects('/categories');
        $this->em($client)->clear();
        self::assertSame('B', $this->em($client)->find(Category::class, $category->getId())?->getName());
    }
}
