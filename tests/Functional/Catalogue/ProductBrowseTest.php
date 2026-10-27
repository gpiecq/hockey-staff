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

final class ProductBrowseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Supplier $supplier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->supplier = (new Supplier())->setName('Bauer');
        $this->em->persist($this->supplier);
        $this->em->flush();
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Viewer, 'v@example.test'));
    }

    /** @param list<string> $path */
    private function product(string $reference, string $designation, array $path = ['EQUIPEMENT', 'JOUEUR'], ?Brand $brand = null, ?Supplier $supplier = null): Product
    {
        $categories = $this->client->getContainer()->get(CategoryRepository::class);
        \assert($categories instanceof CategoryRepository);
        $product = (new Product())
            ->setSupplier($supplier ?? $this->supplier)
            ->setReference($reference)
            ->setDesignation($designation)
            ->setBrand($brand)
            ->setCategory($categories->findOrCreatePath($path))
            ->setTrackingMode(TrackingMode::Quantity);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function variant(Product $product, ?string $ean, string $size = 'SR L', bool $active = true): Variant
    {
        $variant = (new Variant())->setProduct($product)->setSize($size)->setEan($ean)->setActive($active);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    private function text(Crawler $crawler): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $crawler->text());
    }

    public function testExactEanRedirectsToProduct(): void
    {
        $product = $this->product('1028478', 'JUGULAIRE 2 PARTIES');
        $this->variant($product, '067235585931');

        $this->client->request('GET', '/catalogue', ['q' => '067235585931']);

        self::assertResponseRedirects('/catalogue/'.$product->getId());
    }

    public function testUnknownEanFallsBackToSearch(): void
    {
        $this->product('1028478', 'JUGULAIRE 2 PARTIES');

        $this->client->request('GET', '/catalogue', ['q' => '0000000000000']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun produit', $this->text($this->client->getCrawler()));
    }

    public function testSearchIsCaseAndAccentInsensitive(): void
    {
        $this->product('1028478', 'JUGULAIRE 2 PARTIES');
        $this->product('1', 'Casque été');
        $this->product('2', 'COUDIERE');

        $crawler = $this->client->request('GET', '/catalogue', ['q' => 'jugulaire']);
        self::assertStringContainsString('JUGULAIRE 2 PARTIES', $this->text($crawler));
        self::assertStringNotContainsString('COUDIERE', $this->text($crawler));

        $crawler = $this->client->request('GET', '/catalogue', ['q' => 'ETE']);
        self::assertStringContainsString('Casque été', $this->text($crawler));
        self::assertStringNotContainsString('JUGULAIRE', $this->text($crawler));
    }

    public function testSearchByReferenceAndWildcardsAreEscaped(): void
    {
        $this->product('1028478', 'JUGULAIRE');
        $this->product('55', 'COUDIERE');

        $crawler = $this->client->request('GET', '/catalogue', ['q' => '1028478']);
        self::assertStringContainsString('JUGULAIRE', $this->text($crawler));
        self::assertStringNotContainsString('COUDIERE', $this->text($crawler));

        $crawler = $this->client->request('GET', '/catalogue', ['q' => '%']);
        self::assertStringContainsString('Aucun produit', $this->text($crawler));
        $crawler = $this->client->request('GET', '/catalogue', ['q' => '_']);
        self::assertStringContainsString('Aucun produit', $this->text($crawler));
    }

    public function testCategoryFilterIncludesDescendants(): void
    {
        $this->product('A', 'GANT JOUEUR', ['EQUIPEMENT', 'JOUEUR']);
        $this->product('B', 'PATIN X', ['EQUIPEMENT', 'JOUEUR', 'PATIN']);
        $this->product('C', 'BIDON', ['CONSOMMABLE']);
        $root = $this->em->getRepository(\App\Entity\Category::class)->findOneBy(['name' => 'EQUIPEMENT']);
        $mid = $this->em->getRepository(\App\Entity\Category::class)->findOneBy(['name' => 'JOUEUR']);
        self::assertNotNull($root);
        self::assertNotNull($mid);

        $text = $this->text($this->client->request('GET', '/catalogue', ['category' => (string) $root->getId()]));
        self::assertStringContainsString('GANT JOUEUR', $text);
        self::assertStringContainsString('PATIN X', $text);
        self::assertStringNotContainsString('BIDON', $text);

        $text = $this->text($this->client->request('GET', '/catalogue', ['category' => (string) $mid->getId()]));
        self::assertStringContainsString('PATIN X', $text);
        self::assertStringNotContainsString('BIDON', $text);
    }

    public function testSupplierAndBrandFilters(): void
    {
        $other = (new Supplier())->setName('Warrior');
        $brand = (new Brand())->setName('CCM');
        $this->em->persist($other);
        $this->em->persist($brand);
        $this->em->flush();
        $this->product('A', 'PRODUIT BAUER');
        $this->product('B', 'PRODUIT WARRIOR', brand: $brand, supplier: $other);

        $text = $this->text($this->client->request('GET', '/catalogue', ['supplier' => (string) $other->getId()]));
        self::assertStringContainsString('PRODUIT WARRIOR', $text);
        self::assertStringNotContainsString('PRODUIT BAUER', $text);

        $text = $this->text($this->client->request('GET', '/catalogue', ['brand' => (string) $brand->getId()]));
        self::assertStringContainsString('PRODUIT WARRIOR', $text);
        self::assertStringNotContainsString('PRODUIT BAUER', $text);
    }

    public function testProductWithOnlyInactiveVariantsIsHiddenUnlessIncluded(): void
    {
        $inactive = $this->product('A', 'PRODUIT INACTIF');
        $this->variant($inactive, null, 'SR L', false);
        $mixed = $this->product('B', 'PRODUIT MIXTE');
        $this->variant($mixed, null, 'SR L', false);
        $this->variant($mixed, null, 'SR M', true);

        $text = $this->text($this->client->request('GET', '/catalogue'));
        self::assertStringNotContainsString('PRODUIT INACTIF', $text);
        self::assertStringContainsString('PRODUIT MIXTE', $text);

        $text = $this->text($this->client->request('GET', '/catalogue', ['includeInactive' => '1']));
        self::assertStringContainsString('PRODUIT INACTIF', $text);
    }

    public function testSoftDeletedActiveVariantDoesNotKeepProductVisible(): void
    {
        $product = $this->product('A', 'PRODUIT FANTOME');
        $this->variant($product, null, 'SR L', false);
        $gone = $this->variant($product, null, 'SR M', true);
        $this->em->remove($gone);
        $this->em->flush();

        self::assertStringNotContainsString('PRODUIT FANTOME', $this->text($this->client->request('GET', '/catalogue')));
    }

    public function testProductWithoutVariantsStaysVisible(): void
    {
        $this->product('A', 'PRODUIT SANS VARIANTE');

        self::assertStringContainsString('PRODUIT SANS VARIANTE', $this->text($this->client->request('GET', '/catalogue')));
    }

    public function testSoftDeletedProductIsNeverListedNorShown(): void
    {
        $product = $this->product('A', 'PRODUIT SUPPRIME');
        $id = $product->getId();
        $this->em->remove($product);
        $this->em->flush();
        $this->em->clear();

        self::assertStringNotContainsString('PRODUIT SUPPRIME', $this->text($this->client->request('GET', '/catalogue')));
        $this->client->request('GET', '/catalogue/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDisplaysCurrentSeasonPrices(): void
    {
        $product = $this->product('1028478', 'JUGULAIRE 2 PARTIES');
        $priced = $this->variant($product, '067235585931', 'SR L');
        $unpriced = $this->variant($product, null, 'SR M');
        $inactive = $this->variant($product, null, 'SR S', false);
        $old = new Season('2025-2026', new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2026-06-30'));
        $current = new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $current->setCurrent(true);
        $this->em->persist($old);
        $this->em->persist($current);
        $this->em->persist((new SeasonPrice())->setVariant($priced)->setSeason($current)->setClubPriceTtc(1200)->setPublicPriceTtc(1500));
        $this->em->persist((new SeasonPrice())->setVariant($unpriced)->setSeason($old)->setClubPriceTtc(999)->setPublicPriceTtc(999));
        $this->em->persist((new SeasonPrice())->setVariant($inactive)->setSeason($current)->setClubPriceTtc(100));
        $this->em->flush();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId());

        self::assertResponseIsSuccessful();
        $text = $this->text($crawler);
        self::assertStringContainsString('JUGULAIRE 2 PARTIES', $text);
        self::assertStringContainsString('067235585931', $text);
        self::assertStringContainsString('12,00 €', $text);
        self::assertStringContainsString('10,00 €', $text);
        self::assertStringContainsString('15,00 €', $text);
        self::assertStringNotContainsString('9,99', $text);
        self::assertSame(1, substr_count($text, 'Tarif absent'));
        self::assertSame(1, substr_count($text, 'Plus au catalogue'));
    }

    public function testShowWithoutCurrentSeasonSaysTarifAbsent(): void
    {
        $product = $this->product('A', 'PRODUIT');
        $this->variant($product, null);
        $this->em->clear();

        $text = $this->text($this->client->request('GET', '/catalogue/'.$product->getId()));

        self::assertStringContainsString('Tarif absent', $text);
    }

    public function testPaginationCountAndPageTwoKeepsFilters(): void
    {
        for ($i = 1; $i <= 35; ++$i) {
            $this->product(\sprintf('R%02d', $i), \sprintf('GANT %02d', $i));
        }
        $this->product('Z', 'AUTRE');

        $crawler = $this->client->request('GET', '/catalogue', ['q' => 'gant']);
        self::assertStringContainsString('1–30 sur 35 produits', $this->text($crawler));
        $next = $crawler->filter('a[rel="next"]')->attr('href');
        self::assertStringContainsString('q=gant', (string) $next);
        self::assertStringContainsString('page=2', (string) $next);

        $crawler = $this->client->request('GET', (string) $next);
        self::assertStringContainsString('31–35 sur 35 produits', $this->text($crawler));
    }

    public function testFiltersAreAutocompleteSelectsInAGetForm(): void
    {
        $this->product('A', 'PRODUIT');

        $crawler = $this->client->request('GET', '/catalogue');

        $form = $crawler->filter('form[method="get"]');
        self::assertCount(1, $form);
        self::assertCount(0, $form->filter('input[name="_token"]'));
        foreach (['category', 'supplier', 'brand'] as $name) {
            $select = $form->filter(\sprintf('select[name="%s"]', $name));
            self::assertCount(1, $select, $name);
            self::assertStringContainsString('autocomplete', (string) $select->attr('data-controller'));
            self::assertStringContainsString('/autocomplete/', (string) $select->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));
        }
        self::assertCount(1, $crawler->filter('turbo-frame#catalogue-results'));
    }

    public function testCategoryFilterAutocompleteSuggestsAllLevels(): void
    {
        $this->product('A', 'PRODUIT', ['EQUIPEMENT', 'JOUEUR', 'PATIN']);

        $crawler = $this->client->request('GET', '/catalogue');
        $url = (string) $crawler->filter('select[name="category"]')->attr('data-symfony--ux-autocomplete--autocomplete-url-value');
        $this->client->request('GET', $url, ['query' => 'patin']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_array($data['results']));
        self::assertSame(['EQUIPEMENT › JOUEUR › PATIN'], array_column($data['results'], 'text'));
    }

    public function testNavHasProduitsEntryActiveOnCatalogue(): void
    {
        $crawler = $this->client->request('GET', '/catalogue');

        self::assertResponseIsSuccessful();
        self::assertSame('Produits', trim($crawler->filter('nav.sidebar [aria-current="page"]')->text()));
        $bottom = $crawler->filter('nav.bottombar > a')->each(static fn (Crawler $a): string => trim($a->text()));
        self::assertSame(['Tableau de bord', 'Produits'], $bottom);
        self::assertSame('Plus', trim($crawler->filter('nav.bottombar summary')->text()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedInputs(): iterable
    {
        yield 'page too high' => ['page=999', '31–35 sur 35 produits'];
        yield 'page not numeric' => ['page=abc', '1–30 sur 35 produits'];
        yield 'page zero' => ['page=0', '1–30 sur 35 produits'];
        yield 'category not numeric' => ['category=abc', '1–30 sur 35 produits'];
        yield 'category unknown' => ['category=999999', '1–30 sur 35 produits'];
        yield 'supplier array' => ['supplier[]=1', '1–30 sur 35 produits'];
        yield 'query array' => ['q[]=x', '1–30 sur 35 produits'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedInputs')]
    public function testMalformedInputsDegradeGracefully(string $queryString, string $counter): void
    {
        for ($i = 1; $i <= 35; ++$i) {
            $this->product(\sprintf('R%02d', $i), \sprintf('GANT %02d', $i));
        }

        $crawler = $this->client->request('GET', '/catalogue?'.$queryString);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString($counter, $this->text($crawler));
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/catalogue');

        self::assertResponseRedirects();
    }
}
