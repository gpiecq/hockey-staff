<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Catalogue\CodeResolver;
use App\Entity\Item;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CodeResolverTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    private function resolver(): CodeResolver
    {
        $resolver = $this->client->getContainer()->get(CodeResolver::class);
        \assert($resolver instanceof CodeResolver);

        return $resolver;
    }

    private function variant(?string $ean = null, string $reference = 'A1'): Variant
    {
        $product = ImportFixtures::product($this->em, $this->em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($this->em), $reference);

        return ImportFixtures::variant($this->em, $product, $ean, 'SR L');
    }

    private function item(): Item
    {
        $variant = StockScenario::variant($this->em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        $recorder = $this->client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        StockScenario::receive($recorder, $variant, StockFixtures::location($this->em), 1, 8000);
        $item = $this->em->getRepository(Item::class)->findOneBy([]);
        self::assertNotNull($item);

        return $item;
    }

    public function testEanWithOrWithoutLeadingZeroResolves(): void
    {
        $short = $this->variant('123456789012', 'A1');
        $long = $this->variant('0987654321098', 'A2');

        self::assertSame($short, $this->resolver()->resolve('0123456789012'));
        self::assertSame($long, $this->resolver()->resolve('987654321098'));
    }

    public function testVariantCodeResolvesCaseInsensitive(): void
    {
        $variant = $this->variant();

        self::assertSame($variant, $this->resolver()->resolve('  '.strtolower((string) $variant->getCode()).' '));
    }

    public function testItemCodeResolves(): void
    {
        $item = $this->item();

        self::assertSame($item, $this->resolver()->resolve(' '.strtolower($item->getCode())));
    }

    public function testArchivedVariantResolves(): void
    {
        $variant = $this->variant('123456789012');
        $variant->archive();
        $this->em->flush();

        self::assertSame($variant, $this->resolver()->resolve((string) $variant->getCode()));
        self::assertSame($variant, $this->resolver()->resolve('123456789012'));
    }

    public function testDeletedVariantDoesNotResolve(): void
    {
        $variant = $this->variant();
        $code = (string) $variant->getCode();
        $this->em->remove($variant);
        $this->em->flush();

        self::assertNull($this->resolver()->resolve($code));
    }

    public function testUnknownCodeIsNull(): void
    {
        foreach (['HV-999999', 'HS-999999', 'hello', '', '   ', '12345'] as $code) {
            self::assertNull($this->resolver()->resolve($code), $code);
        }
    }

    public function testSearchByVariantCodeRedirectsToProduct(): void
    {
        $variant = $this->variant();
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Viewer, 'v@example.test'));

        $this->client->request('GET', '/catalogue', ['q' => strtolower((string) $variant->getCode())]);

        self::assertResponseRedirects('/catalogue/'.$variant->getProduct()?->getId());
    }

    public function testSearchByItemCodeRedirectsToItem(): void
    {
        $item = $this->item();
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Stock, 'u@example.test'));

        $this->client->request('GET', '/catalogue', ['q' => $item->getCode()]);

        self::assertResponseRedirects('/stock/pieces/'.$item->getCode());
    }
}
