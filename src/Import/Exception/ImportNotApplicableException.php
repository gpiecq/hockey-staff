<?php

declare(strict_types=1);

namespace App\Import\Exception;

/** Le run n'est plus dans un état qui permet de l'appliquer (déjà appliqué, annulé, en échec…). */
final class ImportNotApplicableException extends \LogicException
{
}
