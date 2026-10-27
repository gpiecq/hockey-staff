<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case Admin = 'ROLE_ADMIN';
    case Manager = 'ROLE_MANAGER';
    case Stock = 'ROLE_STOCK';
    case Viewer = 'ROLE_VIEWER';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Stock => 'Responsable matériel',
            self::Viewer => 'Lecture seule',
        };
    }
}
