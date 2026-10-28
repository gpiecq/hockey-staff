<?php

declare(strict_types=1);

namespace App\Enum;

enum PlayerPosition: string
{
    case Goalie = 'goalie';
    case Defense = 'defense';
    case Forward = 'forward';

    public function label(): string
    {
        return match ($this) {
            self::Goalie => 'Gardien',
            self::Defense => 'Défenseur',
            self::Forward => 'Attaquant',
        };
    }
}
