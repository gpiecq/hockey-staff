<?php

declare(strict_types=1);

namespace App\Tests\Functional\Label;

use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\UserRole;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LabelControllerTest extends WebTestCase
{
    private const array EANS = ['REF-S' => '4006381333931', 'REF-Q' => '5901234123457', 'REF-A' => '9780201379624', 'REF-B' => '3017620422003'];

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client, UserRole $role): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    private function variant(KernelBrowser $client, ProductKind $mode, string $reference, int $quantity = 0): Variant
    {
        $em = $this->em($client);
        $variant = StockScenario::variant($em, $mode, $reference, 'Produit '.$reference, self::EANS[$reference] ?? null);
        if ($quantity > 0) {
            $recorder = $client->getContainer()->get(StockMovementRecorder::class);
            \assert($recorder instanceof StockMovementRecorder);
            StockScenario::receive($recorder, $variant, StockFixtures::location($em, 'Local '.$reference), $quantity, 5000);
        }

        return $variant;
    }

    private function csrfToken(KernelBrowser $client, Variant $variant): string
    {
        $crawler = $client->request('GET', '/etiquettes', ['variantes' => [(string) $variant->getId()]]);
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[action$="/etiquettes/pdf"] input[name="_token"]')->attr('value') ?? '';
    }

    /**
     * @param list<array{type: string, id: string, exemplaires: string}> $lines
     * @param array<string, string>                                      $extra
     */
    private function post(KernelBrowser $client, string $token, array $lines, array $extra = []): void
    {
        $client->request('POST', '/etiquettes/pdf', $extra + ['_token' => $token, 'format' => 'a4', 'premiere_case' => '1', 'lignes' => $lines]);
    }

    public function testViewerIsForbidden(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/etiquettes');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/etiquettes/pdf');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPrintOffersVariantLabelsOnly(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $equipment = $this->variant($client, ProductKind::Equipment, 'REF-S', 2);

        $client->request('GET', '/etiquettes', ['variantes' => [(string) $equipment->getId()], 'mode' => 'pieces', 'piece' => 'HS-000001']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[type="radio"][name="mode"]');
        self::assertSelectorExists('input[name="lignes[0][type]"][value="variante"]');
        self::assertSelectorNotExists('input[name="lignes[1][type]"]');
        self::assertSelectorNotExists('input[value="piece"]');
        self::assertSelectorExists('input[name$="[exemplaires]"][value="1"]');
    }

    public function testEmptyScreenShowsMessage(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $client->request('GET', '/etiquettes', ['variantes' => ['999999']]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'Aucune étiquette à imprimer.');
    }

    public function testPdfIsReturned(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $a = $this->variant($client, ProductKind::Consumable, 'REF-A');
        $b = $this->variant($client, ProductKind::Consumable, 'REF-B');
        $token = $this->csrfToken($client, $a);

        $this->post($client, $token, [
            ['type' => 'variante', 'id' => (string) $a->getId(), 'exemplaires' => '2'],
            ['type' => 'variante', 'id' => (string) $b->getId(), 'exemplaires' => '1'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('application/pdf', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline; filename="etiquettes.pdf"', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testTamperedPdfRequestIsHandled(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->variant($client, ProductKind::Consumable, 'REF-A');
        $token = $this->csrfToken($client, $variant);
        $valid = ['type' => 'variante', 'id' => (string) $variant->getId(), 'exemplaires' => '1'];
        $ghost = ['type' => 'variante', 'id' => '999999', 'exemplaires' => '1'];

        $this->post($client, $token, [$valid], ['premiere_case' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La première case doit être comprise entre 1 et 21.');

        $this->post($client, $token, [['type' => 'variante', 'id' => (string) $variant->getId(), 'exemplaires' => '-3'], $ghost]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Aucune étiquette à imprimer.');

        $this->post($client, $token, [$valid, $ghost]);
        self::assertResponseStatusCodeSame(200);

        $deleted = $this->variant($client, ProductKind::Consumable, 'REF-B');
        $deletedId = (string) $deleted->getId();
        $em = $this->em($client);
        $em->remove($deleted);
        $em->flush();
        $this->post($client, $token, [['type' => 'variante', 'id' => $deletedId, 'exemplaires' => '1']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Aucune étiquette à imprimer.');

        $this->post($client, $token, [$valid], ['format' => 'inconnu']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMoreThanFiveHundredLabelsIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->variant($client, ProductKind::Consumable, 'REF-A');
        $token = $this->csrfToken($client, $variant);

        $this->post($client, $token, [['type' => 'variante', 'id' => (string) $variant->getId(), 'exemplaires' => '501']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', '500 étiquettes au maximum par PDF : réduisez la sélection.');
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->variant($client, ProductKind::Consumable, 'REF-A');

        $this->post($client, 'bad', [['type' => 'variante', 'id' => (string) $variant->getId(), 'exemplaires' => '1']]);

        self::assertResponseStatusCodeSame(403);
    }
}
