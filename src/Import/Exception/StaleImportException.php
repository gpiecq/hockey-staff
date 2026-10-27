<?php

declare(strict_types=1);

namespace App\Import\Exception;

/** Le catalogue du fournisseur a changé (autre import appliqué) depuis l'analyse du run. */
final class StaleImportException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le catalogue a été modifié depuis l\'analyse, relancez l\'analyse.');
    }
}
