<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogueCompletionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Season $season;
    private Supplier $supplier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->supplier = (new Supplier())->setName('Bauer');
        $this->season = (new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30')))->setCurrent(true);
        $this->em->persist($this->supplier);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testCountsIgnoreDeletedProductsAndInactiveVariants(): void
    {
        $this->product('FULL', true, [[true, 5000]]);
        $this->product('NOCAT', false, [[true, 100]]);
        $deleted = $this->product('DELCAT', false, [[true, null]]);
        $this->product('INACTIVE', true, [[false, null]]);
        $this->product('NOPRICE', true, [[true, null]]);
        $this->em->remove($deleted);
        $this->em->flush();

        $repo = $this->products();
        self::assertSame(1, $repo->countWithoutCategory());
        self::assertSame(1, $repo->countMissingClubPrice($this->season));
    }

    public function testZeroClubPriceIsAPrice(): void
    {
        $this->product('ZERO', true, [[true, 0]]);

        self::assertSame(0, $this->products()->countMissingClubPrice($this->season));
    }

    public function testCatalogueFilterWithoutCategory(): void
    {
        $this->product('HASCAT', true, [[true, 100]]);
        $this->product('NOCAT', false, [[true, 100]]);
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/catalogue?sansCategorie=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Désignation NOCAT');
        self::assertSelectorTextNotContains('table', 'Désignation HASCAT');
    }

    public function testCatalogueFilterWithoutPrice(): void
    {
        $this->product('PRICED', true, [[true, 100]]);
        $this->product('NOPRICE', true, [[true, null]]);
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/catalogue?sansTarif=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Désignation NOPRICE');
        self::assertSelectorTextNotContains('table', 'Désignation PRICED');
    }

    public function testFiltersIgnoredForNonManager(): void
    {
        $this->product('PRICED', true, [[true, 100]]);
        $this->product('NOPRICE', true, [[true, null]]);
        $this->login(UserRole::Viewer);

        $this->client->request('GET', '/catalogue?sansTarif=1');

        self::assertSelectorTextContains('table', 'Désignation PRICED');
    }

    public function testProductPriceOnlyFillsVariantsWithoutPrice(): void
    {
        $product = $this->product('P', true, [[true, 5000], [true, null], [true, null, true], [false, null]]);
        $this->login(UserRole::Manager);

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/tarif');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Appliquer')->form([
            'product_price[clubPriceTtc]' => '70.00',
            'product_price[publicPriceTtc]' => '90.00',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/catalogue/'.$product->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Tarif appliqué à 2 variante(s).');

        $this->em->clear();
        $variants = $this->em->getRepository(Variant::class)->findBy(['product' => $product->getId()], ['id' => 'ASC']);
        /** @var list<array{0: int|null, 1: int|null}|null> $rows */
        $rows = array_map(function (Variant $v): ?array {
            $price = $this->em->getRepository(SeasonPrice::class)->findOneBy(['variant' => $v]);

            return $price instanceof SeasonPrice ? [$price->getClubPriceTtc(), $price->getPublicPriceTtc()] : null;
        }, $variants);
        self::assertSame([
            [5000, null],
            [7000, 9000],
            [7000, 9000],
            null,
        ], $rows);
    }

    public function testProductPriceRequiresManager(): void
    {
        $product = $this->product('P', true, [[true, null]]);
        $this->login(UserRole::Stock);

        $this->client->request('GET', '/catalogue/'.$product->getId().'/tarif');

        self::assertResponseStatusCodeSame(403);
    }

    private function login(UserRole $role): void
    {
        $this->client->loginUser(UserFactory::create($this->em, $role, 'u@example.test'));
    }

    private function products(): ProductRepository
    {
        $repo = self::getContainer()->get(ProductRepository::class);
        \assert($repo instanceof ProductRepository);

        return $repo;
    }

    /**
     * @param list<array{0: bool, 1: int|null, 2?: bool}> $variants [active, club price (null = none), create the price row (default: only when a price is given)]
     */
    private function product(string $ref, bool $withCategory, array $variants): Product
    {
        $product = (new Product())
            ->setSupplier($this->supplier)
            ->setReference($ref)
            ->setDesignation('Désignation '.$ref)
            ->setTrackingMode(TrackingMode::Quantity);
        if ($withCategory) {
            $categories = self::getContainer()->get(CategoryRepository::class);
            \assert($categories instanceof CategoryRepository);
            $product->setCategory($categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']));
        }
        $this->em->persist($product);
        foreach ($variants as $i => $spec) {
            $variant = (new Variant())->setProduct($product)->setSize('T'.$i)->setActive($spec[0]);
            $this->em->persist($variant);
            $createRow = $spec[2] ?? (null !== $spec[1]);
            if ($createRow) {
                $this->em->persist((new SeasonPrice())->setVariant($variant)->setSeason($this->season)->setClubPriceTtc($spec[1]));
            }
        }
        $this->em->flush();

        return $product;
    }
}
