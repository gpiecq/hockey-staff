<?php

declare(strict_types=1);

namespace App\Label;

enum LabelMode: string
{
    case Items = 'pieces';
    case Variants = 'variantes';
}
