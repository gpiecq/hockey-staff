<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pagination;

use App\Pagination\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testEmptyPage(): void
    {
        $page = new Page([], 1, 30, 0);

        self::assertSame(1, $page->lastPage());
        self::assertSame(0, $page->firstIndex());
        self::assertSame(0, $page->lastIndex());
    }

    public function testLastPageWithPartialContent(): void
    {
        $items = array_fill(0, 1, 'x');
        $page = new Page($items, 3, 30, 61);

        self::assertSame(3, $page->lastPage());
        self::assertSame(61, $page->firstIndex());
        self::assertSame(61, $page->lastIndex());
    }

    public function testFullFirstPage(): void
    {
        $page = new Page(array_fill(0, 30, 'x'), 1, 30, 61);

        self::assertSame(1, $page->firstIndex());
        self::assertSame(30, $page->lastIndex());
    }
}
