<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HotwireNativePathConfigurationTest extends WebTestCase
{
    private const IOS = '/hotwire-native/v1/ios-path-configuration.json';
    private const ANDROID = '/hotwire-native/v1/android-path-configuration.json';

    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    /**
     * @return array{settings: array<string, mixed>, rules: list<array{patterns: list<string>, properties: array<string, mixed>}>}
     */
    private function fetch(string $url): array
    {
        $client = self::$client ??= static::createClient();
        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        /** @var array{settings: array<string, mixed>, rules: list<array{patterns: list<string>, properties: array<string, mixed>}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param list<array{patterns: list<string>, properties: array<string, mixed>}> $rules
     */
    private function contextFor(array $rules, string $path): ?string
    {
        $context = null;
        foreach ($rules as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (1 === preg_match('#'.$pattern.'#', $path)) {
                    $context = \is_string($rule['properties']['context'] ?? null) ? $rule['properties']['context'] : null;
                    break;
                }
            }
        }

        return $context;
    }

    public function testIosConfigurationIsPublicJson(): void
    {
        $data = $this->fetch(self::IOS);

        self::assertSame(['.*'], $data['rules'][0]['patterns']);
    }

    public function testMovementFormsOpenAsModal(): void
    {
        $rules = $this->fetch(self::IOS)['rules'];

        foreach (['/stock/entree/achat', '/stock/sortie', '/stock/transfert', '/stock/variantes/12/pret', '/stock/variantes/12/retour', '/stock/variantes/12/don', '/ventes/new', '/login'] as $path) {
            self::assertSame('modal', $this->contextFor($rules, $path), $path);
        }
    }

    public function testPagesStayDefault(): void
    {
        $rules = $this->fetch(self::IOS)['rules'];

        foreach (['/scan', '/stock/variantes/12', '/stock', '/menu'] as $path) {
            self::assertSame('default', $this->contextFor($rules, $path), $path);
        }
    }

    public function testAndroidConfigurationAddsFragmentUris(): void
    {
        $ios = $this->fetch(self::IOS)['rules'];
        $android = $this->fetch(self::ANDROID)['rules'];

        self::assertCount(\count($ios), $android);
        foreach ($android as $i => $rule) {
            self::assertSame($ios[$i]['patterns'], $rule['patterns']);
            self::assertSame(
                0 === $i ? 'hotwire://fragment/web' : 'hotwire://fragment/web/modal/sheet',
                $rule['properties']['uri'] ?? null,
            );
        }
    }

    public function testPatternsMatchRealRoutes(): void
    {
        $rules = $this->fetch(self::IOS)['rules'];
        /** @var UrlGeneratorInterface $router */
        $router = static::getContainer()->get('router');

        $routes = [
            'stock_entry_new' => ['type' => 'achat'],
            'stock_out_new' => [],
            'stock_transfer_new' => [],
            'stock_loan_new' => ['id' => 12],
            'stock_loan_return' => ['id' => 12],
            'stock_gift_new' => ['id' => 12],
            'sale_new' => [],
            'app_login' => [],
        ];
        foreach ($routes as $name => $params) {
            $path = $router->generate($name, $params);
            self::assertSame('modal', $this->contextFor($rules, $path), $name.' '.$path);
        }
    }
}
