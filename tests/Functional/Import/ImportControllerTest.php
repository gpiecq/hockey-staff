<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\Supplier;
use App\Enum\ImportRunStatus;
use App\Enum\UserRole;
use App\Import\Message\AnalyzeImportRun;
use App\Import\MessageHandler\AnalyzeImportRunHandler;
use App\Tests\Support\CatalogueSpreadsheetFactory;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ImportControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        // Uniquement les fichiers créés par ces tests (le dossier est le volume partagé du dev).
        $dir = \dirname(__DIR__, 3).'/var/storage/imports/';
        foreach ($this->em()->getConnection()->fetchFirstColumn('SELECT stored_path FROM import_run') as $stored) {
            if (\is_string($stored) && '' !== $stored && !str_contains($stored, '..') && is_file($dir.$stored)) {
                @unlink($dir.$stored);
            }
        }
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(UserRole $role = UserRole::Manager): void
    {
        $this->client->loginUser(UserFactory::create($this->em(), $role, 'u@example.test'));
    }

    /** @return list<array<string, mixed>> */
    private function goodRows(string $reference = 'R1', string $ean = '688698508062'): array
    {
        return [[
            'Référence interne' => $reference, 'Gencode' => $ean, 'Désignation' => 'Patin', 'Taille' => 'SR L',
            'PU Public TTC' => '300', 'PU Club TTC' => '250', 'Catégorie' => 'EQUIPEMENT', 'Marque' => 'BAUER',
        ]];
    }

    /** @param list<array<string, mixed>>|null $rows */
    private function analyzedRun(?array $rows = null, ?Supplier $supplier = null): ImportRun
    {
        $em = $this->em();
        $supplier ??= ImportFixtures::supplierWithMapping($em);
        $season = $em->getRepository(Season::class)->findOneBy([]) ?? ImportFixtures::season($em);
        $run = ImportFixtures::run($em, $supplier, $season, $rows ?? $this->goodRows());
        $handler = static::getContainer()->get(AnalyzeImportRunHandler::class);
        \assert($handler instanceof AnalyzeImportRunHandler);
        $handler(new AnalyzeImportRun((int) $run->getId()));

        return $run;
    }

    private function upload(Supplier $supplier, Season $season, string $path, string $name = 'catalogue.xlsx', ?string $mime = null): Crawler
    {
        $crawler = $this->client->request('GET', '/imports/new');
        $token = $crawler->filter('input[name="import_upload[_token]"]')->attr('value');

        return $this->client->request('POST', '/imports/new', [
            'import_upload' => ['supplier' => (string) $supplier->getId(), 'season' => (string) $season->getId(), '_token' => $token],
        ], [
            'import_upload' => ['file' => new UploadedFile($path, $name, $mime, null, true)],
        ]);
    }

    private function xlsxFile(): string
    {
        return $this->tmpFiles[] = CatalogueSpreadsheetFactory::create($this->goodRows());
    }

    private function applyToken(Crawler $crawler, string $action = 'apply'): string
    {
        return $crawler->filter('form[action$="/'.$action.'"] input[name="_token"]')->attr('value') ?? '';
    }

    // --- Upload ---

    public function testUploadCreatesPendingRunAndDispatchesAnalysisMessage(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $season = ImportFixtures::season($this->em());

        $this->upload($supplier, $season, $this->xlsxFile());

        self::assertResponseRedirects();
        $run = $this->em()->getRepository(ImportRun::class)->findOneBy([]);
        self::assertInstanceOf(ImportRun::class, $run);
        self::assertSame(ImportRunStatus::Pending, $run->getStatus());
        self::assertSame('catalogue.xlsx', $run->getOriginalFilename());
        self::assertSame('/imports/'.$run->getId(), $this->client->getResponse()->headers->get('Location'));
        self::assertFileExists(\dirname(__DIR__, 3).'/var/storage/imports/'.$run->getStoredPath());

        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $messages = array_map(static fn ($e) => $e->getMessage(), $transport->getSent());
        self::assertCount(1, $messages);
        self::assertInstanceOf(AnalyzeImportRun::class, $messages[0]);
        self::assertSame($run->getId(), $messages[0]->importRunId);
    }

    public function testUploadFormUsesAutocompleteAndPreselectsCurrentSeason(): void
    {
        $this->login();
        $season = ImportFixtures::season($this->em());
        $season->setCurrent(true);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/imports/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="import_upload[supplier]"][data-controller~="symfony--ux-autocomplete--autocomplete"]');
        self::assertSelectorExists('select[name="import_upload[season]"][data-controller~="symfony--ux-autocomplete--autocomplete"]');
        self::assertSame((string) $season->getId(), $crawler->filter('select[name="import_upload[season]"] option[selected]')->attr('value'));
    }

    public function testNonXlsxFileIsRejectedWithFormError(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $season = ImportFixtures::season($this->em());
        $csv = $this->tmpFiles[] = tempnam(sys_get_temp_dir(), 'imp_').'.csv';
        file_put_contents($csv, "a;b\n1;2\n");

        $this->upload($supplier, $season, $csv, 'catalogue.csv', 'text/csv');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'xlsx');
        self::assertSame(0, $this->em()->getRepository(ImportRun::class)->count([]));
    }

    public function testCsvRenamedXlsxIsRejectedByContentCheck(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $season = ImportFixtures::season($this->em());
        $fake = $this->tmpFiles[] = tempnam(sys_get_temp_dir(), 'imp_').'.xlsx';
        file_put_contents($fake, "a;b\n1;2\n");

        $this->upload($supplier, $season, $fake);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
        self::assertSame(0, $this->em()->getRepository(ImportRun::class)->count([]));
    }

    public function testFileOver20MbIsRejectedWithFormError(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $season = ImportFixtures::season($this->em());
        $big = $this->tmpFiles[] = tempnam(sys_get_temp_dir(), 'imp_').'.xlsx';
        $handle = fopen($big, 'w');
        \assert(false !== $handle);
        ftruncate($handle, 20 * 1000 * 1000 + 1);
        fclose($handle);

        $this->upload($supplier, $season, $big);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
        self::assertSame(0, $this->em()->getRepository(ImportRun::class)->count([]));
    }

    public function testSupplierWithoutMappingIsRejected(): void
    {
        $this->login();
        $supplier = (new Supplier())->setName('Sans mapping');
        $this->em()->persist($supplier);
        $this->em()->flush();
        $season = ImportFixtures::season($this->em());

        $this->upload($supplier, $season, $this->xlsxFile());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Aucun mapping d\'import pour ce fournisseur.');
        self::assertSame(0, $this->em()->getRepository(ImportRun::class)->count([]));
    }

    // --- Show ---

    public function testPendingRunShowsProgressAndAutoRefresh(): void
    {
        $this->login();
        $run = ImportFixtures::run($this->em(), ImportFixtures::supplierWithMapping($this->em()), ImportFixtures::season($this->em()), $this->goodRows());

        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Analyse en cours…');
        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"][content="3"]'));
        self::assertCount(1, $crawler->filter('meta[name="turbo-visit-control"][content="reload"]'));
    }

    public function testAnalyzedRunShowsSummaryAndSections(): void
    {
        $this->login();
        // Catégorie connue : sinon l'avertissement « catégorie inconnue » (ligne 2) passerait avant l'EAN manquant.
        $categories = $this->em()->getRepository(\App\Entity\Category::class);
        \assert($categories instanceof \App\Repository\CategoryRepository);
        $categories->findOrCreatePath(['EQUIPEMENT']);
        $rows = $this->goodRows();
        $rows[] = ['Référence interne' => 'R2', 'Gencode' => '', 'Désignation' => 'Casque', 'Taille' => 'M',
            'PU Public TTC' => '100', 'PU Club TTC' => '80', 'Catégorie' => 'EQUIPEMENT', 'Marque' => 'BAUER'];
        $run = $this->analyzedRun($rows);

        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
        $text = $crawler->filter('main')->text();
        foreach (['Nouveautés', 'Modifications', 'Prix', 'Désactivations', 'Anomalies'] as $section) {
            self::assertStringContainsString($section, $text);
        }
        self::assertStringContainsString('2 nouveaux produits', $text);
        self::assertStringContainsString('2 nouvelles variantes', $text);
        // L'anomalie « EAN manquant » de la ligne 3 est affichée avec son numéro de ligne Excel.
        self::assertSelectorExists('#anomalies td.num');
        self::assertStringContainsString('3', $crawler->filter('#anomalies td.num')->first()->text());
        self::assertSelectorExists('form[action$="/apply"]');
        self::assertSelectorExists('form[action$="/cancel"]');
    }

    public function testPreviewShowsUncategorizedCount(): void
    {
        $this->login();
        $rows = array_merge($this->goodRows('R1', '688698508062'), $this->goodRows('R2', '688698508079'));
        foreach ($rows as &$row) {
            $row['Catégorie'] = null;
        }
        unset($row);
        $run = $this->analyzedRun($rows);

        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 produits sans catégorie', $crawler->filter('main')->text());
    }

    public function testPreviewListsNewBrands(): void
    {
        $this->login();
        // Marque existante (BAUER) sur R1, marque inconnue sur R2 ; R3 existe déjà et change vers une autre marque inconnue.
        $em = $this->em();
        $supplier = ImportFixtures::supplierWithMapping($em);
        $r3 = ImportFixtures::product($em, $supplier, 'R3', 'Patin', 'Bauer');
        ImportFixtures::variant($em, $r3, '688698508086', 'SR L');
        $existing = $this->goodRows('R1', '688698508062');
        $new = $this->goodRows('R2', '688698508079');
        $new[0]['Marque'] = 'NOUVELLE MARQUE';
        $changed = $this->goodRows('R3', '688698508086');
        $changed[0]['Marque'] = 'AUTRE MARQUE';
        $run = $this->analyzedRun(array_merge($existing, $new, $changed), $supplier);

        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        $list = $crawler->filter('#marques-creees');
        self::assertCount(1, $list);
        self::assertStringContainsString('NOUVELLE MARQUE', $list->text());
        self::assertStringContainsString('AUTRE MARQUE', $list->text());
        self::assertStringNotContainsString('BAUER', $list->text());
    }

    public function testPreviewShowsSansCategorieForUncategorizedProducts(): void
    {
        $this->login();
        $rows = $this->goodRows();
        $rows[0]['Catégorie'] = null;
        $run = $this->analyzedRun($rows);

        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        self::assertStringContainsString('Sans catégorie', $crawler->filter('#nouveautes')->text());
    }

    public function testFailedRunShowsTheError(): void
    {
        $this->login();
        $run = ImportFixtures::run($this->em(), ImportFixtures::supplierWithMapping($this->em()), ImportFixtures::season($this->em()), $this->goodRows());
        $run->markFailed('Colonnes obligatoires manquantes dans le fichier : « Désignation ».');
        $this->em()->flush();

        $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error, .form-error, .import-error', 'Colonnes obligatoires manquantes');
        self::assertSelectorNotExists('form[action$="/apply"]');
    }

    // --- Apply / cancel ---

    public function testApplyAppliesTheImportAndFlashesCounts(): void
    {
        $this->login();
        $run = $this->analyzedRun();
        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        $this->client->request('POST', '/imports/'.$run->getId().'/apply', ['_token' => $this->applyToken($crawler)]);

        self::assertResponseRedirects('/imports/'.$run->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Import appliqué : 2 créations, 0 modifications, 0 désactivations.');
        self::assertSame(ImportRunStatus::Applied, $this->em()->find(ImportRun::class, $run->getId())?->getStatus());
        self::assertSame(1, $this->em()->getRepository(Product::class)->count([]));
        self::assertSelectorNotExists('form[action$="/apply"]');
    }

    public function testApplyRejectsInvalidCsrfToken(): void
    {
        $this->login();
        $run = $this->analyzedRun();

        $this->client->request('POST', '/imports/'.$run->getId().'/apply', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ImportRunStatus::Analyzed, $this->em()->find(ImportRun::class, $run->getId())?->getStatus());
    }

    public function testStaleImportFlashesErrorAndLeavesRunUnchanged(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $first = $this->analyzedRun($this->goodRows('R1', '688698508062'), $supplier);
        $second = $this->analyzedRun($this->goodRows('R2', '688698508079'), $supplier);
        $crawlerFirst = $this->client->request('GET', '/imports/'.$first->getId());
        $tokenFirst = $this->applyToken($crawlerFirst);
        $crawlerSecond = $this->client->request('GET', '/imports/'.$second->getId());
        $tokenSecond = $this->applyToken($crawlerSecond);
        sleep(1); // colonnes TIMESTAMP(0) : l'application doit être strictement postérieure à l'analyse

        $this->client->request('POST', '/imports/'.$first->getId().'/apply', ['_token' => $tokenFirst]);
        self::assertResponseRedirects();

        $this->client->request('POST', '/imports/'.$second->getId().'/apply', ['_token' => $tokenSecond]);

        self::assertResponseRedirects('/imports/'.$second->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertSelectorTextContains('.flash-error', 'relancez l\'analyse');
        self::assertSame(ImportRunStatus::Analyzed, $this->em()->find(ImportRun::class, $second->getId())?->getStatus());
        self::assertSame(1, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testApplyOnRunNoLongerAnalyzedFlashesErrorInsteadOfCrashing(): void
    {
        $this->login();
        $run = $this->analyzedRun();
        $crawler = $this->client->request('GET', '/imports/'.$run->getId());
        $token = $this->applyToken($crawler);
        $this->client->request('POST', '/imports/'.$run->getId().'/apply', ['_token' => $token]);

        $this->client->request('POST', '/imports/'.$run->getId().'/apply', ['_token' => $token]);

        self::assertResponseRedirects('/imports/'.$run->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertSame(1, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testApplyWithDeletedFileFlashesReadableErrorAndKeepsRunAnalyzed(): void
    {
        $this->login();
        $run = $this->analyzedRun();
        $crawler = $this->client->request('GET', '/imports/'.$run->getId());
        $token = $this->applyToken($crawler);
        unlink(\dirname(__DIR__, 3).'/var/storage/imports/'.$run->getStoredPath());

        $this->client->request('POST', '/imports/'.$run->getId().'/apply', ['_token' => $token]);

        self::assertResponseRedirects('/imports/'.$run->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'n\'a pas pu être appliqué');
        self::assertSelectorTextContains('.flash-error', 'tableur');
        $status = $this->em()->getConnection()->fetchOne('SELECT status FROM import_run WHERE id = ?', [$run->getId()]);
        self::assertSame('analyzed', $status);
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testCancelMarksRunCancelled(): void
    {
        $this->login();
        $run = $this->analyzedRun();
        $crawler = $this->client->request('GET', '/imports/'.$run->getId());

        $this->client->request('POST', '/imports/'.$run->getId().'/cancel', ['_token' => $this->applyToken($crawler, 'cancel')]);

        self::assertResponseRedirects('/imports/'.$run->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-success');
        self::assertSame(ImportRunStatus::Cancelled, $this->em()->find(ImportRun::class, $run->getId())?->getStatus());
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testCancelRejectsInvalidCsrfToken(): void
    {
        $this->login();
        $run = $this->analyzedRun();

        $this->client->request('POST', '/imports/'.$run->getId().'/cancel', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
    }

    // --- Rôles et menu ---

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'index' => ['GET', '/imports'];
        yield 'new' => ['GET', '/imports/new'];
        yield 'new post' => ['POST', '/imports/new'];
        yield 'show' => ['GET', '/imports/{id}'];
        yield 'apply' => ['POST', '/imports/{id}/apply'];
        yield 'cancel' => ['POST', '/imports/{id}/cancel'];
    }

    /** @dataProvider routes */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testStockIsForbiddenOnEveryRoute(string $method, string $url): void
    {
        $run = $this->analyzedRun();
        $this->login(UserRole::Stock);

        $this->client->request($method, str_replace('{id}', (string) $run->getId(), $url));

        self::assertResponseStatusCodeSame(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testViewerIsForbiddenOnEveryRoute(string $method, string $url): void
    {
        $run = $this->analyzedRun();
        $this->login(UserRole::Viewer);

        $this->client->request($method, str_replace('{id}', (string) $run->getId(), $url));

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerSeesImportsMenuEntry(): void
    {
        $this->login();

        $this->client->request('GET', '/imports');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/imports"]');
        self::assertSelectorExists('.bottombar .more-panel a[href="/imports"]');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lowRoles')]
    public function testStockAndViewerDoNotSeeImportsMenuEntry(UserRole $role): void
    {
        $this->login($role);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/imports"]');
    }

    /** @return iterable<string, array{UserRole}> */
    public static function lowRoles(): iterable
    {
        yield 'stock' => [UserRole::Stock];
        yield 'viewer' => [UserRole::Viewer];
    }

    // --- Liste ---

    public function testIndexIsPaginatedNewestFirst(): void
    {
        $this->login();
        $supplier = ImportFixtures::supplierWithMapping($this->em());
        $season = ImportFixtures::season($this->em());
        for ($i = 1; $i <= 31; ++$i) {
            $this->em()->persist(new ImportRun($supplier, $season, \sprintf('fichier-%02d.xlsx', $i), 'x'.$i.'.xlsx'));
        }
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/imports');

        self::assertResponseIsSuccessful();
        self::assertCount(30, $crawler->filter('tbody tr'));
        self::assertStringContainsString('fichier-31.xlsx', $crawler->filter('tbody tr')->first()->text());
        self::assertStringContainsString('1–30 sur 31 imports', $crawler->filter('.pagination-count')->text());

        $crawler = $this->client->request('GET', '/imports?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('fichier-01.xlsx', $crawler->filter('tbody tr')->text());
    }

    public function testEmptyIndexShowsMessageAndNewImportButton(): void
    {
        $this->login();

        $this->client->request('GET', '/imports');

        self::assertSelectorTextContains('.empty', 'Aucun import');
        self::assertSelectorExists('a[href="/imports/new"]');
    }
}
