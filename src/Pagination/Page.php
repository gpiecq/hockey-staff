<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Une page de résultats d'une liste paginée.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @return self<mixed>
     */
    public static function fromQuery(QueryBuilder $qb, int|string|null $page, int $perPage = 30): self
    {
        $requested = is_numeric($page) ? (int) $page : 1;

        $qb->setFirstResult(0)->setMaxResults($perPage);
        $paginator = new Paginator($qb);
        $total = \count($paginator);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $current = min(max(1, $requested), $lastPage);

        $qb->setFirstResult(($current - 1) * $perPage);

        return new self(iterator_to_array(new Paginator($qb), false), $current, $perPage, $total);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function firstIndex(): int
    {
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function lastIndex(): int
    {
        return 0 === $this->total ? 0 : min($this->total, $this->page * $this->perPage);
    }
}
