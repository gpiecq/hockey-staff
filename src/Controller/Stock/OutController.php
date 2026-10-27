<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Enum\StockOutReason;
use App\Form\Stock\StockLineMapper;
use App\Form\Stock\StockOutType;
use App\Repository\ItemRepository;
use App\Stock\Input\StockOut;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_MOVE')]
final class OutController extends AbstractController
{
    #[Route('/stock/sortie', name: 'stock_out_new', methods: ['GET', 'POST'])]
    public function new(Request $request, StockMovementRecorder $recorder, StockLineMapper $mapper, ItemRepository $items): Response
    {
        $data = ['lines' => [['itemCode' => null, 'variant' => null, 'quantity' => 1]]];
        $code = $request->query->getString('item');
        $item = '' !== $code ? $items->findOneByCode($code) : null;
        if (null !== $item) {
            $data = ['location' => $item->getLocation(), 'lines' => [['itemCode' => $item->getCode(), 'variant' => null, 'quantity' => null]]];
        }

        $form = $this->createForm(StockOutType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{location: Location, reason: StockOutReason, comment: ?string} $values */
            $values = $form->getData();
            $lines = $mapper->map($form->get('lines'));
            if (null !== $lines) {
                try {
                    $operation = $recorder->recordOut(new StockOut($values['location'], $values['reason'], $lines, $this->nullIfBlank($values['comment'] ?? null)));
                } catch (StockException $e) {
                    // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point.
                    $form->addError(new FormError($e->getMessage()));

                    return $this->renderForm($form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('success', 'Sortie enregistrée.');

                return $this->redirectToRoute('stock_operation_show', ['id' => $operation->getId()]);
            }
        }

        return $this->renderForm($form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function renderForm(\Symfony\Component\Form\FormInterface $form, int $status): Response
    {
        return $this->render('stock/out/form.html.twig', ['form' => $form], new Response(status: $status));
    }

    private function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
