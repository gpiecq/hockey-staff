<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Form\Stock\StockLineMapper;
use App\Repository\LocationRepository;
use App\Repository\VariantRepository;
use App\Stock\StockException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Socle commun des écrans de sortie et de transfert (préremplissage par article et lieu, soumission, rendu).
 */
trait StockOperationScreen
{
    private function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    /**
     * Données initiales du formulaire ; `?variant=<id>` préremplit la ligne et `?lieu=<id>` le lieu `$locationField` (identifiant inconnu ou lieu inactif : ignoré).
     *
     * @return array<string, mixed>
     */
    private function initialData(Request $request, VariantRepository $variants, LocationRepository $locations, string $locationField): array
    {
        $data = ['lines' => [['itemCode' => null, 'variant' => null, 'quantity' => 1]]];

        $variantId = $request->query->getString('variant');
        $variant = ctype_digit($variantId) ? $variants->find((int) $variantId) : null;
        if ($variant instanceof Variant) {
            $data['lines'][0]['variant'] = $variant;
        }

        $locationId = $request->query->getString('lieu');
        $location = ctype_digit($locationId) ? $locations->find((int) $locationId) : null;
        if ($location instanceof Location && $location->isActive()) {
            $data[$locationField] = $location;
        }

        return $data;
    }

    /**
     * @param callable(list<\App\Stock\Input\StockLine>): StockOperation $record
     */
    private function handleOperation(Request $request, FormInterface $form, StockLineMapper $mapper, callable $record, string $template, string $flash): Response
    {
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $lines = $mapper->map($form->get('lines'));
            if (null !== $lines) {
                try {
                    $operation = $record($lines);
                } catch (StockException $e) {
                    // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point, rendu en lecture seule.
                    $form->addError(new FormError($e->getMessage()));

                    return $this->render($template, ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                $this->addFlash('success', $flash);

                return $this->redirectToRoute('stock_operation_show', ['id' => $operation->getId()]);
            }
        }

        return $this->render($template, ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
