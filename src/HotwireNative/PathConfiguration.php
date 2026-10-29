<?php

declare(strict_types=1);

namespace App\HotwireNative;

/**
 * Hotwire Native path configuration: rules are applied in order, the last matching one wins.
 *
 * @phpstan-type Rule array{patterns: list<string>, properties: array<string, mixed>}
 * @phpstan-type Configuration array{settings: array<string, mixed>, rules: list<Rule>}
 */
final readonly class PathConfiguration
{
    private const array DEFAULT_PATTERNS = ['.*'];

    private const array MODAL_PATTERNS = [
        '^/stock/entree/',
        '^/stock/sortie$',
        '^/stock/transfert$',
        '^/stock/variantes/\d+/(pret|retour|don)$',
        '^/ventes/new$',
    ];

    private const array LOGIN_PATTERNS = ['^/login$'];

    private const string ANDROID_DEFAULT_URI = 'hotwire://fragment/web';

    private const string ANDROID_MODAL_URI = 'hotwire://fragment/web/modal/sheet';

    /**
     * @return Configuration
     */
    public function ios(): array
    {
        return $this->build(null, null);
    }

    /**
     * @return Configuration
     */
    public function android(): array
    {
        return $this->build(self::ANDROID_DEFAULT_URI, self::ANDROID_MODAL_URI);
    }

    /**
     * @return Configuration
     */
    private function build(?string $defaultUri, ?string $modalUri): array
    {
        return [
            'settings' => [],
            'rules' => [
                ['patterns' => self::DEFAULT_PATTERNS, 'properties' => $this->properties('default', true, $defaultUri)],
                ['patterns' => self::MODAL_PATTERNS, 'properties' => $this->properties('modal', false, $modalUri)],
                ['patterns' => self::LOGIN_PATTERNS, 'properties' => $this->properties('modal', false, $modalUri)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(string $context, bool $pullToRefresh, ?string $uri): array
    {
        $properties = ['context' => $context, 'pull_to_refresh_enabled' => $pullToRefresh];
        if (null !== $uri) {
            $properties['uri'] = $uri;
        }

        return $properties;
    }
}
