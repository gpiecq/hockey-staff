<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Supplier;
use App\Enum\UserRole;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreparationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(UserRole $role): void
    {
        $this->client->loginUser(UserFactory::create($this->em(), $role, 'u@example.test'));
    }

    public function testManagerSeesStepsAndDownloadLinks(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $withoutMapping = (new Supplier())->setName('Sans mapping');
        $this->em()->persist($withoutMapping);
        $this->em()->flush();
        $this->login(UserRole::Manager);

        $crawler = $this->client->request('GET', '/imports/preparer');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('ol.prepare-steps > li'));
        $text = $crawler->filter('main')->text();
        foreach (['Télécharger', 'IA', 'compte rendu', 'Nouvel import'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertCount(1, $crawler->filter(\sprintf('a[href="/imports/preparer/%d/template.xlsx"]', $supplier->getId())));
        self::assertCount(0, $crawler->filter(\sprintf('a[href="/imports/preparer/%d/template.xlsx"]', $withoutMapping->getId())));
        self::assertCount(1, $crawler->filter('a[href="/imports/preparer/regles.md"]'));
        self::assertCount(1, $crawler->filter('a[href="/imports/preparer/correspondances.csv"]'));
        self::assertCount(1, $crawler->filter('a[href="/imports/new"]'));
    }

    public function testStockUserIsDenied(): void
    {
        $this->login(UserRole::Stock);

        $this->client->request('GET', '/imports/preparer');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTemplateDownload(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $this->login(UserRole::Manager);

        $this->client->request('GET', \sprintf('/imports/preparer/%d/template.xlsx', $supplier->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('modele-import-bauer.xlsx', $disposition);
    }

    public function testTemplateOfSupplierWithoutMappingIsNotFound(): void
    {
        $supplier = (new Supplier())->setName('Sans mapping');
        $this->em()->persist($supplier);
        $this->em()->flush();
        $this->login(UserRole::Manager);

        $this->client->request('GET', \sprintf('/imports/preparer/%d/template.xlsx', $supplier->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRulesDownload(): void
    {
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/imports/preparer/regles.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=UTF-8');
        $response = $this->client->getResponse();
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('regles-ia.md', (string) $response->headers->get('Content-Disposition'));
        $content = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Gencode', $content);
        self::assertStringContainsString('laisser vide', $content);
    }

    public function testCorrespondencesDownload(): void
    {
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/imports/preparer/correspondances.csv');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $content = (string) $this->client->getInternalResponse()->getContent();
        self::assertSame('onglet;section;categorie1;categorie2;categorie3;marque;candidates', strtok($content, "\n"));
    }
}
