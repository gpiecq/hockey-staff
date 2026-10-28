<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Form\Stock\StockLoanType;
use App\Form\Stock\StockReturnType;
use App\Repository\ItemRepository;
use App\Repository\LocationRepository;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockReturn;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_MOVE')]
final class LoanController extends AbstractController
{
    use VariantFormScreen;

    #[Route('/stock/variantes/{id}/pret', name: 'stock_loan_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function loan(int $id, Request $request, EntityManagerInterface $em, ItemRepository $items, LocationRepository $locations, StockMovementRecorder $recorder): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');
        $location = $this->selectedLocation($request, $locations, 'stock_loan');
        $form = $this->createForm(StockLoanType::class, ['location' => $location], ['counts' => $this->countsAt($items, $variant, $location)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{location: Location, player: Player, condition: ItemCondition, comment: ?string} $values */
            $values = $form->getData();
            try {
                $operation = $recorder->recordLoan(new StockLoan($values['location'], $values['player'], $variant, $values['condition'], $this->nullIfBlank($values['comment'] ?? null)));
            } catch (StockException $e) {
                // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point.
                $form->addError(new FormError($e->getMessage()));

                return $this->page($variant, $form, 'stock/loan/form.html.twig', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $movement = $operation->getMovements()->first();
            $this->addFlash('success', \sprintf('%s prêté à %s (%s).', $variant->getLabel(), $values['player']->getFullName(), false === $movement ? '?' : ($movement->getItem()?->getCode() ?? '?')));

            return $this->redirectToRoute('stock_variant_show', ['id' => $id]);
        }

        return $this->page($variant, $form, 'stock/loan/form.html.twig', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/stock/variantes/{id}/retour', name: 'stock_loan_return', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function return(int $id, Request $request, EntityManagerInterface $em, ItemRepository $items, StockMovementRecorder $recorder): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');
        $loaned = $items->findLoanedByVariant($variant);
        $preselected = null;
        $code = $request->query->getString('item');
        foreach ($loaned as $candidate) {
            if ('' !== $code && strtoupper(trim($code)) === $candidate->getCode()) {
                $preselected = $candidate;
            }
        }
        if (null === $preselected && 1 === \count($loaned)) {
            $preselected = $loaned[0];
        }
        $form = $this->createForm(StockReturnType::class, ['item' => $preselected], ['loaned' => $loaned]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{item: Item, location: Location, condition: ItemCondition, comment: ?string} $values */
            $values = $form->getData();
            $holder = $values['item']->getHolder();
            if (null === $holder) {
                $form->addError(new FormError('Cette pièce n\'est pas prêtée.'));

                return $this->page($variant, $form, 'stock/loan/return.html.twig', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $holderId = $holder->getId();
            try {
                $recorder->recordReturn(new StockReturn($values['item'], $holder, $values['location'], $values['condition'], $this->nullIfBlank($values['comment'] ?? null)));
            } catch (StockException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->page($variant, $form, 'stock/loan/return.html.twig', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', 'Retour enregistré.');

            return $this->redirectToRoute('player_show', ['id' => $holderId]);
        }

        return $this->page($variant, $form, 'stock/loan/return.html.twig', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function page(Variant $variant, FormInterface $form, string $template, int $status): Response
    {
        return $this->render($template, ['variant' => $variant, 'form' => $form], new Response(status: $status));
    }
}
