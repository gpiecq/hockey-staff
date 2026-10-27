<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Enum\StockOutReason;
use App\Form\Stock\StockLineMapper;
use App\Form\Stock\StockOutType;
use App\Repository\ItemRepository;
use App\Stock\Input\StockOut;
use App\Stock\StockMovementRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_MOVE')]
final class OutController extends AbstractController
{
    use StockOperationScreen;

    #[Route('/stock/sortie', name: 'stock_out_new', methods: ['GET', 'POST'])]
    public function new(Request $request, StockMovementRecorder $recorder, StockLineMapper $mapper, ItemRepository $items): Response
    {
        $form = $this->createForm(StockOutType::class, $this->initialData($request, $items, 'location'));

        return $this->handleOperation($request, $form, $mapper, function (array $lines) use ($form, $recorder) {
            /** @var array{location: Location, reason: StockOutReason, comment: ?string} $values */
            $values = $form->getData();

            return $recorder->recordOut(new StockOut($values['location'], $values['reason'], $lines, $this->nullIfBlank($values['comment'] ?? null)));
        }, 'stock/out/form.html.twig', 'Sortie enregistrée.');
    }
}
