<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\ImportAnomalyCode;

final readonly class ImportAnomaly
{
    public function __construct(
        public int $line,
        public ImportAnomalyCode $code,
        public string $message,
    ) {
    }
}
