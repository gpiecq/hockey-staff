<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Repository\CategoryRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ProductEditTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Supplier $supplier;
    private Brand $brand;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->supplier = (new Supplier())->setName('Bauer');
        $this->brand = (new Brand())->setName('Warrior');
        $this->em->persist($this->supplier);
        $this->em->persist($this->brand);
        $this->em->flush();
    }

    private function login(UserRole $role): void
    {
        $this->client->loginUser(UserFactory::create($this->em, $role, 'u@example.test'));
    }

    /** @param list<string> $path */
    private function product(string $reference = 'R1', array $path = ['EQUIPEMENT', 'JOUEUR']): Product
    {
        $categories = $this->client->getContainer()->get(CategoryRepository::class);
        \assert($categories instanceof CategoryRepository);
        $product = (new Product())
            ->setSupplier($this->supplier)
            ->setReference($reference)
            ->setDesignation('Casque')
            ->setCategory($categories->findOrCreatePath($path))
            ->setTrackingMode(TrackingMode::Quantity);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function variant(Product $product, ?string $ean, string $size = 'SR L'): Variant
    {
        $variant = (new Variant())->setProduct($product)->setSize($size)->setEan($ean);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    private function currentSeason(): Season
    {
        $season = new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $season->setCurrent(true);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function categoryId(string ...$path): int
    {
        $categories = $this->client->getContainer()->get(CategoryRepository::class);
        \assert($categories instanceof CategoryRepository);

        return (int) $categories->findOrCreatePath(array_values($path))->getId();
    }

    /**
     * Les champs d'autocomplétion n'ont pas d'options côté serveur : on poste les valeurs brutes du formulaire.
     *
     * @param array<string, mixed> $overrides
     */
    private function post(Crawler $crawler, string $button, string $name, array $overrides): Crawler
    {
        $form = $crawler->selectButton($button)->form();
        $values = $form->getPhpValues();
        foreach ($overrides as $field => $value) {
            $values[$name][$field] = $value;
        }

        return $this->client->request('POST', $form->getUri(), $values);
    }

    /** @return array<string, mixed> */
    private function productFields(?int $categoryId = null, string $reference = 'NEW1'): array
    {
        return [
            'supplier' => (string) $this->supplier->getId(),
            'reference' => $reference,
            'designation' => 'Casque Bauer',
            'brand' => (string) $this->brand->getId(),
            'category' => (string) ($categoryId ?? $this->categoryId('EQUIPEMENT', 'JOUEUR')),
        ];
    }

    private function findProduct(string $reference): Product
    {
        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    public function testFormShowsAutocompleteFieldsAndTwoUnselectedRadios(): void
    {
        $this->login(UserRole::Manager);

        $crawler = $this->client->request('GET', '/catalogue/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="product[supplier]"][data-controller~="symfony--ux-autocomplete--autocomplete"]');
        self::assertSelectorExists('select[name="product[brand]"][data-controller~="symfony--ux-autocomplete--autocomplete"]');
        self::assertSelectorExists('select[name="product[category]"][data-controller~="symfony--ux-autocomplete--autocomplete"]');
        $radios = $crawler->filter('input[type=radio][name="product[trackingMode]"]');
        self::assertCount(2, $radios);
        self::assertCount(0, $crawler->filter('input[type=radio]:checked'));
        self::assertStringContainsString('Sérialisé', $crawler->filter('form')->text());
        self::assertStringContainsString('Quantité', $crawler->filter('form')->text());
    }

    public function testCreateWithoutTrackingModeUsesEquipmentDefault(): void
    {
        $this->login(UserRole::Manager);
        $crawler = $this->client->request('GET', '/catalogue/new');

        $this->post($crawler, 'Enregistrer', 'product', $this->productFields());

        $product = $this->findProduct('NEW1');
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        self::assertSame(TrackingMode::Serialized, $product->getTrackingMode());
        self::assertSame('WARRIOR', $product->getBrand()?->getName());
    }

    public function testCreateWithoutTrackingModeUsesQuantityOutsideEquipment(): void
    {
        $this->login(UserRole::Manager);
        $crawler = $this->client->request('GET', '/catalogue/new');

        $this->post($crawler, 'Enregistrer', 'product', $this->productFields($this->categoryId('CONSOMMABLE', 'TAPE')));

        self::assertSame(TrackingMode::Quantity, $this->findProduct('NEW1')->getTrackingMode());
    }

    public function testCreateKeepsExplicitTrackingMode(): void
    {
        $this->login(UserRole::Manager);
        $crawler = $this->client->request('GET', '/catalogue/new');

        $this->post($crawler, 'Enregistrer', 'product', $this->productFields() + ['trackingMode' => 'quantity']);

        self::assertSame(TrackingMode::Quantity, $this->findProduct('NEW1')->getTrackingMode());
    }

    public function testInvalidProductRendersErrorsWith422(): void
    {
        $this->login(UserRole::Manager);
        $this->product('DUP');
        $crawler = $this->client->request('GET', '/catalogue/new');

        $this->post($crawler, 'Enregistrer', 'product', $this->productFields(null, 'DUP'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Cette référence existe déjà chez ce fournisseur.');

        $this->post($crawler, 'Enregistrer', 'product', ['supplier' => '', 'reference' => '', 'designation' => '', 'category' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEditShowsStoredTrackingModeAndAllowsChange(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('E1');

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[type=radio][value=quantity]:checked'));

        $this->post($crawler, 'Enregistrer', 'product', ['trackingMode' => 'serialized', 'designation' => 'Casque V2']);

        $product = $this->findProduct('E1');
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        self::assertSame(TrackingMode::Serialized, $product->getTrackingMode());
        self::assertSame('Casque V2', $product->getDesignation());
    }

    public function testProductSoftDeleteWithCsrfHidesProduct(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('D1');
        $id = $product->getId();

        $this->client->request('GET', '/catalogue/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/catalogue');
        $this->client->request('GET', '/catalogue/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testProductDeleteRejectsBadCsrf(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('D2');

        $this->client->request('POST', '/catalogue/'.$product->getId().'/delete', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/catalogue/'.$product->getId());
        self::assertResponseIsSuccessful();
    }

    public function testManagerCreatesVariantAndDuplicateEanIsRejected(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('V1');
        $this->variant($product, '1234567890123', 'SR M');

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/variantes/new');
        self::assertResponseIsSuccessful();
        $this->post($crawler, 'Enregistrer', 'variant', ['ean' => '1234567890123', 'size' => 'SR L']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce code EAN est déjà utilisé.');

        $this->post($crawler, 'Enregistrer', 'variant', ['ean' => '9999999999999', 'size' => 'SR L', 'color' => 'noir']);
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        $this->em->clear();
        $created = $this->em->getRepository(Variant::class)->findOneBy(['ean' => '9999999999999']);
        self::assertInstanceOf(Variant::class, $created);
        self::assertSame('SR L · noir', $created->getLabel());
        self::assertSame($product->getId(), $created->getProduct()?->getId());
    }

    public function testDuplicateAttributesAreRejected(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('V2');
        $this->variant($product, null, 'SR L');

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/variantes/new');
        $this->post($crawler, 'Enregistrer', 'variant', ['size' => 'SR L']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Cette variante existe déjà pour ce produit.');
    }

    public function testManagerEditsAndSoftDeletesVariant(): void
    {
        $this->login(UserRole::Manager);
        $product = $this->product('V3');
        $variant = $this->variant($product, null, 'SR L');
        $id = $variant->getId();

        $crawler = $this->client->request('GET', '/catalogue/variantes/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $this->post($crawler, 'Enregistrer', 'variant', ['size' => 'SR XL']);
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        $this->em->clear();
        self::assertSame('SR XL', $this->em->find(Variant::class, $id)?->getSize());

        $this->client->request('GET', '/catalogue/variantes/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->selectButton('Confirmer la suppression')->form());
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT deleted_at FROM variant WHERE id = ?', [$id]));
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT deleted_at FROM variant WHERE id = ?', [$id]));
    }

    public function testVariantDeleteRejectsBadCsrf(): void
    {
        $this->login(UserRole::Manager);
        $variant = $this->variant($this->product('V4'), null);

        $this->client->request('POST', '/catalogue/variantes/'.$variant->getId().'/delete', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPriceIsParsedInFrenchAndStoredInCents(): void
    {
        $this->login(UserRole::Manager);
        $season = $this->currentSeason();
        $variant = $this->variant($this->product('P1'), null);

        $crawler = $this->client->request('GET', '/catalogue/variantes/'.$variant->getId().'/tarif');
        self::assertResponseIsSuccessful();
        $this->post($crawler, 'Enregistrer', 'season_price', ['clubPriceTtc' => '12,50', 'publicPriceTtc' => '']);

        self::assertResponseRedirects('/catalogue/'.$variant->getProduct()?->getId());
        $this->em->clear();
        $price = $this->em->getRepository(SeasonPrice::class)->findOneBy(['season' => $season->getId()]);
        self::assertInstanceOf(SeasonPrice::class, $price);
        self::assertSame(1250, $price->getClubPriceTtc());
        self::assertNull($price->getPublicPriceTtc());
        self::assertSame(2000, $price->getVatRate());
    }

    public function testExistingPriceIsEditedNotDuplicated(): void
    {
        $this->login(UserRole::Manager);
        $season = $this->currentSeason();
        $variant = $this->variant($this->product('P2'), null);
        $this->em->persist((new SeasonPrice())->setVariant($variant)->setSeason($season)->setClubPriceTtc(1200)->setPublicPriceTtc(1500));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/catalogue/variantes/'.$variant->getId().'/tarif');
        self::assertSame('12,00', $crawler->filter('input[name="season_price[clubPriceTtc]"]')->attr('value'));
        $this->post($crawler, 'Enregistrer', 'season_price', ['clubPriceTtc' => '13,99']);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SeasonPrice::class)->findAll());
        self::assertSame(1399, $this->em->getRepository(SeasonPrice::class)->findAll()[0]->getClubPriceTtc());
    }

    public function testNegativePriceIsRejected(): void
    {
        $this->login(UserRole::Manager);
        $this->currentSeason();
        $variant = $this->variant($this->product('P3'), null);

        $crawler = $this->client->request('GET', '/catalogue/variantes/'.$variant->getId().'/tarif');
        $this->post($crawler, 'Enregistrer', 'season_price', ['clubPriceTtc' => '-3']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Le prix doit être positif.');
    }

    public function testNoCurrentSeasonShowsMessageAndNoForm(): void
    {
        $this->login(UserRole::Manager);
        $variant = $this->variant($this->product('P4'), null);

        $this->client->request('GET', '/catalogue/variantes/'.$variant->getId().'/tarif');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune saison courante');
        self::assertSelectorNotExists('form[name=season_price]');
    }

    public function testBuiltInValidatorMessagesAreFrench(): void
    {
        $this->login(UserRole::Manager);
        $this->currentSeason();
        $variant = $this->variant($this->product('P5'), null);

        $crawler = $this->client->request('GET', '/catalogue/variantes/'.$variant->getId().'/tarif');
        $this->post($crawler, 'Enregistrer', 'season_price', ['clubPriceTtc' => 'abc']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Veuillez saisir un montant valide.');
    }

    public function testShowLinksVisibleToManagerOnly(): void
    {
        $this->currentSeason();
        $product = $this->product('S1');
        $variant = $this->variant($product, null);

        $this->login(UserRole::Manager);
        $this->client->request('GET', '/catalogue/'.$product->getId());
        self::assertSelectorExists('a[href="/catalogue/'.$product->getId().'/edit"]');
        self::assertSelectorExists('a[href="/catalogue/variantes/'.$variant->getId().'/edit"]');
        self::assertSelectorExists('a[href="/catalogue/variantes/'.$variant->getId().'/tarif"]');

        $this->client->loginUser(UserFactory::create($this->em, UserRole::Viewer, 'v@example.test'));
        $this->client->request('GET', '/catalogue/'.$product->getId());
        self::assertSelectorNotExists('a[href$="/edit"]');
        self::assertSelectorNotExists('a[href$="/tarif"]');
    }

    public function testVariantRoutesOfDeletedProductAreNotFound(): void
    {
        $this->login(UserRole::Manager);
        $this->currentSeason();
        $product = $this->product('DEL1');
        $variant = $this->variant($product, null, 'SR L');
        $variantId = $variant->getId();
        $productId = $product->getId();
        $this->em->remove($product);
        $this->em->flush();
        $this->em->clear();

        foreach (['/edit', '/delete', '/tarif'] as $suffix) {
            $this->client->request('GET', '/catalogue/variantes/'.$variantId.$suffix);
            self::assertResponseStatusCodeSame(404, 'GET '.$suffix);
        }
        $this->client->request('POST', '/catalogue/variantes/'.$variantId.'/edit', ['variant' => ['size' => 'HACK']]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/catalogue/variantes/'.$variantId.'/delete', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/catalogue/variantes/'.$variantId.'/tarif', ['season_price' => ['clubPriceTtc' => '1']]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/catalogue/'.$productId.'/variantes/new');
        self::assertResponseStatusCodeSame(404);

        self::assertSame('SR L', $this->em->getConnection()->fetchOne('SELECT size FROM variant WHERE id = ?', [$variantId]));
    }

    /** @return iterable<string, array{0: UserRole, 1: string, 2: string}> */
    public static function forbiddenRoutes(): iterable
    {
        foreach ([UserRole::Stock, UserRole::Viewer] as $role) {
            foreach ([
                ['GET', '/catalogue/new'],
                ['POST', '/catalogue/new'],
                ['GET', '/catalogue/{p}/edit'],
                ['POST', '/catalogue/{p}/edit'],
                ['GET', '/catalogue/{p}/delete'],
                ['POST', '/catalogue/{p}/delete'],
                ['GET', '/catalogue/{p}/variantes/new'],
                ['POST', '/catalogue/{p}/variantes/new'],
                ['GET', '/catalogue/variantes/{v}/edit'],
                ['POST', '/catalogue/variantes/{v}/edit'],
                ['GET', '/catalogue/variantes/{v}/delete'],
                ['POST', '/catalogue/variantes/{v}/delete'],
                ['GET', '/catalogue/variantes/{v}/tarif'],
                ['POST', '/catalogue/variantes/{v}/tarif'],
            ] as [$method, $uri]) {
                yield $role->value.' '.$method.' '.$uri => [$role, $method, $uri];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenRoutes')]
    public function testStockAndViewerAreForbidden(UserRole $role, string $method, string $uri): void
    {
        $this->login($role);
        $this->currentSeason();
        $product = $this->product('F1');
        $variant = $this->variant($product, null);

        $this->client->request($method, str_replace(['{p}', '{v}'], [(string) $product->getId(), (string) $variant->getId()], $uri));

        self::assertResponseStatusCodeSame(403);
    }
}
