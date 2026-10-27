<?php

declare(strict_types=1);

namespace App\Stock;

/**
 * Règle de gestion de stock violée ; le message (en français) est affiché tel quel à l'utilisateur.
 */
final class StockException extends \DomainException
{
}
