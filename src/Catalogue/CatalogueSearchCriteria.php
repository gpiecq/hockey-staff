<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class CatalogueSearchCriteria
{
    public function __construct(
        public ?string $query = null,
        public ?int $categoryId = null,
        public ?int $supplierId = null,
        public ?int $brandId = null,
        public bool $includeInactive = false,
        public int $page = 1,
    ) {
    }
}
