<?php

declare(strict_types=1);

namespace App\Label;

enum Symbology: string
{
    case UpcA = 'upca';
    case Ean13 = 'ean13';
    case Code128 = 'code128';
}
