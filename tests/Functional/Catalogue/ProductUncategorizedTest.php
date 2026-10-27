<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Product;
use App\Entity\Supplier;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Repository\CategoryRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductUncategorizedTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Product $product;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $supplier = (new Supplier())->setName('Bauer');
        $this->product = (new Product())
            ->setSupplier($supplier)
            ->setReference('NOCAT')
            ->setDesignation('Article sans rubrique')
            ->setTrackingMode(TrackingMode::Quantity);
        $this->em->persist($supplier);
        $this->em->persist($this->product);
        $this->em->flush();
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Manager, 'u@example.test'));
    }

    public function testUncategorizedProductAppearsInCatalogueList(): void
    {
        $this->client->request('GET', '/catalogue');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Article sans rubrique');
        self::assertSelectorTextContains('table', 'Sans catégorie');
    }

    public function testUncategorizedProductShowPage(): void
    {
        $this->client->request('GET', '/catalogue/'.$this->product->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.product-meta', 'Sans catégorie');
    }

    public function testManualCreateStillRequiresCategory(): void
    {
        $crawler = $this->client->request('GET', '/catalogue/new');
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['product']['supplier'] = (string) $this->product->getSupplier()?->getId();
        $values['product']['reference'] = 'NEW';
        $values['product']['designation'] = 'Sans catégorie';
        $values['product']['category'] = '';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'La catégorie est obligatoire.');
    }

    public function testFirstCategoryAppliesDefaultTrackingMode(): void
    {
        $this->editWithCategory($this->product, ['EQUIPEMENT', 'JOUEUR'], null);

        self::assertSame(TrackingMode::Serialized, $this->reload()->getTrackingMode());
    }

    public function testUserChangedTrackingModeWinsOnFirstCategory(): void
    {
        $this->product->setTrackingMode(TrackingMode::Serialized);
        $this->em->flush();

        $this->editWithCategory($this->product, ['EQUIPEMENT', 'JOUEUR'], TrackingMode::Quantity);

        self::assertSame(TrackingMode::Quantity, $this->reload()->getTrackingMode());
    }

    public function testCategoryChangeOnCategorizedProductKeepsMode(): void
    {
        $this->product->setCategory($this->categories()->findOrCreatePath(['CONSOMMABLE', 'TAPE']));
        $this->em->flush();

        $this->editWithCategory($this->product, ['EQUIPEMENT', 'JOUEUR'], null);

        self::assertSame(TrackingMode::Quantity, $this->reload()->getTrackingMode());
    }

    private function categories(): CategoryRepository
    {
        $categories = $this->client->getContainer()->get(CategoryRepository::class);
        \assert($categories instanceof CategoryRepository);

        return $categories;
    }

    private function reload(): Product
    {
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->product->getId());
        \assert($product instanceof Product);

        return $product;
    }

    /** @param list<string> $path */
    private function editWithCategory(Product $product, array $path, ?TrackingMode $mode): void
    {
        $category = $this->categories()->findOrCreatePath($path);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['product']['category'] = (string) $category->getId();
        if (null !== $mode) {
            $values['product']['trackingMode'] = $mode->value;
        }
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
    }
}
