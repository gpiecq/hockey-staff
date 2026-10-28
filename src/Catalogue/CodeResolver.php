<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Entity\Variant;
use App\Repository\VariantRepository;

/**
 * Résout un code saisi ou scanné (HV-… ou EAN 12/13 chiffres) vers la variante correspondante.
 * La clé de contrôle de l'EAN n'est pas vérifiée : un code inconnu donne null.
 */
final readonly class CodeResolver
{
    public function __construct(
        private VariantRepository $variants,
        private CatalogueSearch $search,
    ) {
    }

    public function resolve(string $code): ?Variant
    {
        $code = strtoupper(trim($code));

        return match (true) {
            1 === preg_match('/^HV-\d+$/', $code) => $this->variants->findOneByCode($code),
            1 === preg_match('/^\d{12,13}$/', $code) => $this->search->findVariantByEan($code),
            default => null,
        };
    }
}
