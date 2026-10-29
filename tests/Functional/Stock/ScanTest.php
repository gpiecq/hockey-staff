<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\UserRole;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScanTest extends WebTestCase
{
    private const EAN = '0012345678905';

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client): EntityManagerInterface
    {
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Viewer, 'u@example.test'));

        return $em;
    }

    private function variant(EntityManagerInterface $em, ?string $ean = self::EAN): Variant
    {
        return StockScenario::variant($em, ProductKind::Equipment, 'REF', 'Désignation', $ean);
    }

    public function testScanPageShowsCodeField(): void
    {
        $client = static::createClient();
        $this->login($client);

        $client->request('GET', '/scan');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="code"]');
        self::assertSelectorTextSame('h1', 'Scanner');
        self::assertSelectorNotExists('.flash-error');
    }

    public function testScanPageWiresScannerControllers(): void
    {
        $client = static::createClient();
        $this->login($client);

        $client->request('GET', '/scan');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-scan-form][data-controller~="scanner"][data-controller~="bridge--barcode-scanner"]');
        self::assertSelectorTextContains('button[data-scanner-target="webButton"]', 'Scanner avec la caméra');
        self::assertSelectorExists('button[data-bridge--barcode-scanner-target="nativeButton"][hidden]');
        self::assertSelectorTextContains('button[data-bridge--barcode-scanner-target="nativeButton"]', 'Scanner');
        self::assertSelectorExists('video[data-scanner-target="video"][hidden][playsinline][muted]');
        self::assertSelectorExists('[role="status"][data-scanner-target="status"]');
        self::assertSelectorExists('input#scan-code[data-scanner-target="input"][data-bridge--barcode-scanner-target="input"]');
    }

    public function testEanRedirectsToVariantSheet(): void
    {
        $client = static::createClient();
        $variant = $this->variant($this->login($client));

        $client->request('GET', '/scan?code='.self::EAN);

        self::assertResponseRedirects('/stock/variantes/'.$variant->getId());
    }

    public function testEanWithoutLeadingZeroRedirects(): void
    {
        $client = static::createClient();
        $variant = $this->variant($this->login($client));

        $client->request('GET', '/scan?code=012345678905');

        self::assertResponseRedirects('/stock/variantes/'.$variant->getId());
    }

    public function testInternalCodeRedirectsWhateverCaseAndWhitespace(): void
    {
        $client = static::createClient();
        $variant = $this->variant($this->login($client));

        $client->request('GET', '/scan', ['code' => ' '.strtolower((string) $variant->getCode())."\n"]);

        self::assertResponseRedirects('/stock/variantes/'.$variant->getId());
    }

    public function testUnknownCodeShowsMessageAndCatalogueLink(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/scan?code=999');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'Code inconnu : 999');
        self::assertSame('999', $crawler->filter('input[name="code"]')->attr('value'));
        $link = $crawler->selectLink('Chercher dans le catalogue');
        self::assertCount(1, $link);
        self::assertSame('/catalogue?q=999', $link->attr('href'));
    }

    public function testInactiveVariantStillResolves(): void
    {
        $client = static::createClient();
        $em = $this->login($client);
        $variant = $this->variant($em);
        $variant->setActive(false);
        $em->flush();

        $client->request('GET', '/scan?code='.self::EAN);

        self::assertResponseRedirects('/stock/variantes/'.$variant->getId());
    }

    public function testArchivedVariantStillResolves(): void
    {
        $client = static::createClient();
        $em = $this->login($client);
        $variant = $this->variant($em);
        $variant->archive();
        $em->flush();

        $client->request('GET', '/scan?code='.self::EAN);
        self::assertResponseRedirects('/stock/variantes/'.$variant->getId());

        $client->followRedirect();
        self::assertSelectorTextContains('.badge', 'Archivée');
    }

    public function testDeletedProductCodeIsUnknown(): void
    {
        $client = static::createClient();
        $em = $this->login($client);
        $variant = $this->variant($em);
        $code = $variant->getCode();
        $product = $variant->getProduct();
        self::assertNotNull($product);
        $em->remove($product);
        $em->flush();

        $client->request('GET', '/scan', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'Code inconnu');

        $client->request('GET', '/scan?code='.self::EAN);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'Code inconnu');
    }

    public function testEmptyCodeShowsPageWithoutMessage(): void
    {
        $client = static::createClient();
        $this->login($client);

        $client->request('GET', '/scan?code=%20');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.flash-error');
        self::assertAnySelectorTextNotContains('body', 'Code inconnu');
    }

    public function testScanEntryInMenu(): void
    {
        $client = static::createClient();
        $this->login($client);

        $client->request('GET', '/');

        self::assertSelectorTextContains('nav.bottombar a[href="/scan"]', 'Scanner');
    }
}
