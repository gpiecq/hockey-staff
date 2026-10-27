<?php

declare(strict_types=1);

namespace App\Enum;

/** Pending correspond au statut DRAFT de la spec (R4). */
enum ImportRunStatus: string
{
    case Pending = 'pending';
    case Analyzed = 'analyzed';
    case Failed = 'failed';
    case Applied = 'applied';
    case Cancelled = 'cancelled';
}
