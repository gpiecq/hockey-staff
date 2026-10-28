<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Form\Stock\StockEntryType;
use App\Repository\LocationRepository;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_MOVE')]
final class EntryController extends AbstractController
{
    use StockOperationScreen;

    #[Route('/stock/entree/{type}', name: 'stock_entry_new', requirements: ['type' => 'achat|dotation'], methods: ['GET', 'POST'])]
    public function new(string $type, Request $request, StockMovementRecorder $recorder, LocationRepository $locations): Response
    {
        $isPurchase = 'achat' === $type;
        $movementType = $isPurchase ? StockMovementType::PurchaseIn : StockMovementType::DonationIn;
        $title = $isPurchase ? 'Nouvel achat' : 'Nouvelle dotation';

        if ([] === $locations->findActive()) {
            return $this->render('stock/entry/form.html.twig', ['form' => null, 'title' => $title, 'type' => $type]);
        }

        $form = $this->createForm(StockEntryType::class, ['lines' => [['quantity' => 1, 'condition' => StockCondition::New]]], ['with_cost' => $isPurchase]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $operation = $recorder->recordEntry($this->toEntry($movementType, $form));
            } catch (StockException $e) {
                // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point, rendu en lecture seule.
                $form->addError(new FormError($e->getMessage()));

                return $this->renderForm($form, $title, $type, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', $this->flash($operation));

            return $this->redirectToRoute('stock_operation_show', ['id' => $operation->getId()]);
        }

        return $this->renderForm($form, $title, $type, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function renderForm(FormInterface $form, string $title, string $type, int $status): Response
    {
        return $this->render('stock/entry/form.html.twig', ['form' => $form, 'title' => $title, 'type' => $type], new Response(status: $status));
    }

    private function toEntry(StockMovementType $type, FormInterface $form): StockEntry
    {
        /** @var array{location: Location, documentReference: ?string, comment: ?string, lines: list<array{variant: Variant, quantity: int, unitCostHt?: int|float|null, condition?: StockCondition|null, serialNumbers?: string|null}>} $data */
        $data = $form->getData();
        $lines = [];
        foreach ($data['lines'] as $row) {
            $serials = preg_split('/\R/', $row['serialNumbers'] ?? '') ?: [];
            $lines[] = new StockEntryLine($row['variant'], $row['quantity'], isset($row['unitCostHt']) ? (int) round($row['unitCostHt']) : null, $row['condition'] ?? StockCondition::New, $serials);
        }

        return new StockEntry($type, $data['location'], $lines, $this->nullIfBlank($data['documentReference'] ?? null), $this->nullIfBlank($data['comment'] ?? null));
    }

    private function flash(StockOperation $operation): string
    {
        $codes = [];
        foreach ($operation->getMovements() as $movement) {
            $code = $movement->getItem()?->getCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }
        if ([] === $codes) {
            return 'Entrée enregistrée.';
        }

        return \sprintf('Entrée enregistrée : %d pièce(s) créée(s) : %s', \count($codes), implode(', ', $codes));
    }
}
