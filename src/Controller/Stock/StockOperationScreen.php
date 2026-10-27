<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Item;
use App\Entity\StockOperation;
use App\Form\Stock\StockLineMapper;
use App\Repository\ItemRepository;
use App\Stock\StockException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Socle commun des écrans de sortie et de transfert (préremplissage par pièce, soumission, rendu).
 */
trait StockOperationScreen
{
    private function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    /**
     * Données initiales du formulaire ; `?item=HS-…` préremplit la ligne et le lieu `$locationField` (code inconnu : formulaire vide).
     *
     * @return array<string, mixed>
     */
    private function initialData(Request $request, ItemRepository $items, string $locationField): array
    {
        $code = $request->query->getString('item');
        $item = '' !== $code ? $items->findOneByCode($code) : null;
        if ($item instanceof Item) {
            return [$locationField => $item->getLocation(), 'lines' => [['itemCode' => $item->getCode(), 'variant' => null, 'quantity' => null]]];
        }

        return ['lines' => [['itemCode' => null, 'variant' => null, 'quantity' => 1]]];
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
