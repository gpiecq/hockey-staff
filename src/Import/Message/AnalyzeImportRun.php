<?php

declare(strict_types=1);

namespace App\Import\Message;

final readonly class AnalyzeImportRun
{
    public function __construct(public int $importRunId)
    {
    }
}
