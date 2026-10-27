<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Brand;
use App\Enum\UserRole;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrandControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function add(KernelBrowser $client, string $name): Brand
    {
        $entity = (new Brand())->setName($name);
        $this->em($client)->persist($entity);
        $this->em($client)->flush();

        return $entity;
    }

    private function login(KernelBrowser $client, UserRole $role): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    public function testViewerSeesListAndMenuButNoActions(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $this->add($client, 'Alpha');

        $client->request('GET', '/marques');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/marques"]');
        self::assertSelectorTextContains('tbody', 'ALPHA');
        self::assertSelectorNotExists('a[href="/marques/new"]');
        self::assertSelectorNotExists('tbody a');
    }

    public function testListIsPaginated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        for ($i = 1; $i <= 35; ++$i) {
            $this->add($client, \sprintf('N%02d', $i));
        }

        $crawler = $client->request('GET', '/marques');
        self::assertCount(30, $crawler->filter('tbody tr'));
        $crawler = $client->request('GET', '/marques?page=2');
        self::assertCount(5, $crawler->filter('tbody tr'));
    }

    public function testStockCannotWrite(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $entity = $this->add($client, 'Alpha');

        $client->request('GET', '/marques/new');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/marques/'.$entity->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/marques/'.$entity->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCreatesWithNormalizedNameAndAuthor(): void
    {
        $client = static::createClient();
        $manager = UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test');
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/marques/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['brand[name]' => '  bauer   hockey ']));

        self::assertResponseRedirects('/marques');
        $this->em($client)->clear();
        $entity = $this->em($client)->getRepository(Brand::class)->findOneBy(['name' => 'BAUER HOCKEY']);
        self::assertInstanceOf(Brand::class, $entity);
        self::assertSame($manager->getId(), $entity->getCreatedBy()?->getId());
    }

    public function testDuplicateNameRendersErrorWith422(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->add($client, 'Bauer');

        $crawler = $client->request('GET', '/marques/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['brand[name]' => 'bauer']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce nom est déjà utilisé.');
    }

    public function testBlankNameRendersErrorWith422(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/marques/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['brand[name]' => '   ']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
    }

    public function testManagerEdits(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $crawler = $client->request('GET', '/marques/'.$entity->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['brand[name]' => 'beta']));

        self::assertResponseRedirects('/marques');
        $this->em($client)->clear();
        self::assertSame('BETA', $this->em($client)->find(Brand::class, $entity->getId())?->getName());
    }

    public function testManagerSoftDeletesAfterConfirmation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $client->request('GET', '/marques');
        $client->clickLink('Supprimer');
        self::assertResponseIsSuccessful();
        $client->submit($client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/marques');
        $client->followRedirect();
        self::assertSelectorNotExists('tbody tr');
        $deletedAt = $this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM brand WHERE id = ?', [$entity->getId()]);
        self::assertNotFalse($deletedAt);
        self::assertNotNull($deletedAt);
    }

    private function postDelete(KernelBrowser $client, Brand $entity): void
    {
        $client->request('GET', '/marques/'.$entity->getId().'/delete');
        $client->submit($client->getCrawler()->selectButton('Confirmer la suppression')->form());
    }

    private function isDeleted(KernelBrowser $client, Brand $entity): bool
    {
        return false !== $this->em($client)->getConnection()->fetchOne('SELECT 1 FROM brand WHERE id = ? AND deleted_at IS NOT NULL', [$entity->getId()]);
    }

    public function testDeleteIsRefusedWhileProductsExist(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $supplier = ImportFixtures::supplierWithMapping($this->em($client));
        $product = ImportFixtures::product($this->em($client), $supplier, 'REF-1', brand: 'Alpha');
        self::assertSame($entity->getId(), $product->getBrand()?->getId());

        $this->postDelete($client, $entity);

        self::assertResponseRedirects('/marques');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Cette marque a encore des produits : suppression impossible.');
        self::assertFalse($this->isDeleted($client, $entity));
    }

    public function testDeleteIsAllowedWhenOnlySoftDeletedProductsRemain(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $supplier = ImportFixtures::supplierWithMapping($this->em($client));
        $product = ImportFixtures::product($this->em($client), $supplier, 'REF-1', brand: 'Alpha');
        self::assertSame($entity->getId(), $product->getBrand()?->getId());
        $this->em($client)->remove($product);
        $this->em($client)->flush();

        $this->postDelete($client, $entity);

        self::assertResponseRedirects('/marques');
        self::assertTrue($this->isDeleted($client, $entity));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $client->request('POST', '/marques/'.$entity->getId().'/delete', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM brand WHERE id = ?', [$entity->getId()]));
    }

    public function testSoftDeletedNameCanBeRecreated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $this->em($client)->remove($entity);
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/marques/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['brand[name]' => 'alpha']));

        self::assertResponseRedirects('/marques');
        self::assertEquals(2, $this->em($client)->getConnection()->fetchOne('SELECT COUNT(*) FROM brand'));
    }
}
