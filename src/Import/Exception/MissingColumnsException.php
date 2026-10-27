<?php

declare(strict_types=1);

namespace App\Import\Exception;

final class MissingColumnsException extends \RuntimeException
{
    /** @param list<string> $missingHeaders en-têtes sources manquants, tels que définis dans le mapping */
    public function __construct(public readonly array $missingHeaders)
    {
        parent::__construct(\sprintf(
            'Colonnes obligatoires manquantes dans le fichier : %s.',
            implode(', ', array_map(static fn (string $h): string => '« '.$h.' »', $missingHeaders)),
        ));
    }
}
