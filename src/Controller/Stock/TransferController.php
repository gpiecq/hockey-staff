<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Form\Stock\StockLineMapper;
use App\Form\Stock\StockTransferType;
use App\Repository\ItemRepository;
use App\Stock\Input\StockTransfer;
use App\Stock\StockMovementRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_MOVE')]
final class TransferController extends AbstractController
{
    use StockOperationScreen;

    #[Route('/stock/transfert', name: 'stock_transfer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, StockMovementRecorder $recorder, StockLineMapper $mapper, ItemRepository $items): Response
    {
        $form = $this->createForm(StockTransferType::class, $this->initialData($request, $items, 'from'));

        return $this->handleOperation($request, $form, $mapper, function (array $lines) use ($form, $recorder) {
            /** @var array{from: Location, to: Location, comment: ?string} $values */
            $values = $form->getData();

            return $recorder->recordTransfer(new StockTransfer($values['from'], $values['to'], $lines, $this->nullIfBlank($values['comment'] ?? null)));
        }, 'stock/transfer/form.html.twig', 'Transfert enregistré.');
    }
}
