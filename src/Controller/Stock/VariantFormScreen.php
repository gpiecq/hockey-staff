<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\Variant;
use App\Repository\ItemRepository;
use App\Repository\LocationRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Socle commun des écrans Prêter / Retour / Donner d'une variante.
 */
trait VariantFormScreen
{
    private function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    /**
     * Lieu actif choisi : valeur soumise du formulaire, sinon `?lieu=` (rechargement du cadre), sinon null.
     */
    private function selectedLocation(Request $request, LocationRepository $locations, string $formName): ?Location
    {
        $submitted = $request->request->all($formName)['location'] ?? null;
        $raw = \is_scalar($submitted) ? (string) $submitted : $request->query->getString('lieu');
        if ('' !== $raw && ctype_digit($raw)) {
            $location = $locations->find((int) $raw);
            if ($location instanceof Location && $location->isActive()) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function countsAt(ItemRepository $items, Variant $variant, ?Location $location): array
    {
        return null === $location ? [] : $items->countInStockByCondition($variant, $location);
    }
}
