<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Supplier;
use App\Enum\UserRole;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SupplierControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function add(KernelBrowser $client, string $name): Supplier
    {
        $entity = (new Supplier())->setName($name);
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

        $client->request('GET', '/fournisseurs');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/fournisseurs"]');
        self::assertSelectorTextContains('tbody', 'ALPHA');
        self::assertSelectorNotExists('a[href="/fournisseurs/new"]');
        self::assertSelectorNotExists('tbody a');
    }

    public function testListIsPaginated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        for ($i = 1; $i <= 35; ++$i) {
            $this->add($client, \sprintf('N%02d', $i));
        }

        $crawler = $client->request('GET', '/fournisseurs');
        self::assertCount(30, $crawler->filter('tbody tr'));
        $crawler = $client->request('GET', '/fournisseurs?page=2');
        self::assertCount(5, $crawler->filter('tbody tr'));
    }

    public function testStockCannotWrite(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $entity = $this->add($client, 'Alpha');

        $client->request('GET', '/fournisseurs/new');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/fournisseurs/'.$entity->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/fournisseurs/'.$entity->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCreatesWithNormalizedNameAndAuthor(): void
    {
        $client = static::createClient();
        $manager = UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test');
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/fournisseurs/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['supplier[name]' => '  bauer   hockey ']));

        self::assertResponseRedirects('/fournisseurs');
        $this->em($client)->clear();
        $entity = $this->em($client)->getRepository(Supplier::class)->findOneBy(['name' => 'BAUER HOCKEY']);
        self::assertInstanceOf(Supplier::class, $entity);
        self::assertSame($manager->getId(), $entity->getCreatedBy()?->getId());
    }

    public function testDuplicateNameRendersErrorWith422(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $this->add($client, 'Bauer');

        $crawler = $client->request('GET', '/fournisseurs/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['supplier[name]' => 'bauer']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce nom est déjà utilisé.');
    }

    public function testBlankNameRendersErrorWith422(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/fournisseurs/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['supplier[name]' => '   ']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
    }

    public function testManagerEdits(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $crawler = $client->request('GET', '/fournisseurs/'.$entity->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['supplier[name]' => 'beta']));

        self::assertResponseRedirects('/fournisseurs');
        $this->em($client)->clear();
        self::assertSame('BETA', $this->em($client)->find(Supplier::class, $entity->getId())?->getName());
    }

    public function testManagerSoftDeletesAfterConfirmation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $client->request('GET', '/fournisseurs');
        $client->clickLink('Supprimer');
        self::assertResponseIsSuccessful();
        $client->submit($client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/fournisseurs');
        $client->followRedirect();
        self::assertSelectorNotExists('tbody tr');
        $deletedAt = $this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM supplier WHERE id = ?', [$entity->getId()]);
        self::assertNotFalse($deletedAt);
        self::assertNotNull($deletedAt);
    }

    private function postDelete(KernelBrowser $client, Supplier $entity): void
    {
        $client->request('GET', '/fournisseurs/'.$entity->getId().'/delete');
        $client->submit($client->getCrawler()->selectButton('Confirmer la suppression')->form());
    }

    private function isDeleted(KernelBrowser $client, Supplier $entity): bool
    {
        return false !== $this->em($client)->getConnection()->fetchOne('SELECT 1 FROM supplier WHERE id = ? AND deleted_at IS NOT NULL', [$entity->getId()]);
    }

    public function testDeleteIsRefusedWhileProductsExist(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $product = ImportFixtures::product($this->em($client), $entity, 'REF-1');

        $this->postDelete($client, $entity);

        self::assertResponseRedirects('/fournisseurs');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Ce fournisseur a encore des produits : suppression impossible.');
        self::assertFalse($this->isDeleted($client, $entity));
    }

    public function testDeleteIsAllowedWhenOnlySoftDeletedProductsRemain(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $product = ImportFixtures::product($this->em($client), $entity, 'REF-1');
        $this->em($client)->remove($product);
        $this->em($client)->flush();

        $this->postDelete($client, $entity);

        self::assertResponseRedirects('/fournisseurs');
        self::assertTrue($this->isDeleted($client, $entity));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');

        $client->request('POST', '/fournisseurs/'.$entity->getId().'/delete', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM supplier WHERE id = ?', [$entity->getId()]));
    }

    public function testSoftDeletedNameCanBeRecreated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $entity = $this->add($client, 'Alpha');
        $this->em($client)->remove($entity);
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/fournisseurs/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['supplier[name]' => 'alpha']));

        self::assertResponseRedirects('/fournisseurs');
        self::assertEquals(2, $this->em($client)->getConnection()->fetchOne('SELECT COUNT(*) FROM supplier'));
    }
}
