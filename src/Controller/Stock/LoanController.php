<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\StockCondition;
use App\Form\Stock\StockLoanType;
use App\Form\Stock\StockReturnType;
use App\Repository\LocationRepository;
use App\Repository\PlayerHoldingRepository;
use App\Repository\StockLevelRepository;
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
    public function loan(int $id, Request $request, EntityManagerInterface $em, StockLevelRepository $levels, LocationRepository $locations, StockMovementRecorder $recorder): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');
        $location = $this->selectedLocation($request, $locations, 'stock_loan');
        $form = $this->createForm(StockLoanType::class, ['location' => $location, 'quantity' => 1], ['counts' => $this->countsAt($levels, $variant, $location)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{location: Location, player: Player, condition: StockCondition, quantity: int, comment: ?string} $values */
            $values = $form->getData();
            try {
                $recorder->recordLoan(new StockLoan($values['location'], $values['player'], $variant, $values['condition'], $values['quantity'], $this->nullIfBlank($values['comment'] ?? null)));
            } catch (StockException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->page($variant, $form, 'stock/loan/form.html.twig', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', \sprintf('Prêt enregistré : %d × %s à %s.', $values['quantity'], $variant->getLabel() ?: 'Unique', $values['player']->getFullName()));

            return $this->redirectToRoute('stock_variant_show', ['id' => $id]);
        }

        return $this->page($variant, $form, 'stock/loan/form.html.twig', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/stock/variantes/{id}/retour', name: 'stock_loan_return', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function return(int $id, Request $request, EntityManagerInterface $em, PlayerHoldingRepository $holdings, LocationRepository $locations, StockMovementRecorder $recorder): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');
        $holders = $holdings->findByVariant($variant);
        // Joueur présélectionné : celui passé par la fiche joueur (?joueur=), sinon le détenteur unique.
        $preselected = 1 === \count($holders) ? $holders[0]->getPlayer() : null;
        $playerId = $request->query->getInt('joueur');
        foreach ($holders as $holding) {
            if ($playerId > 0 && $holding->getPlayer()->getId() === $playerId) {
                $preselected = $holding->getPlayer();
            }
        }
        $location = $this->selectedLocation($request, $locations, 'stock_return');
        $form = $this->createForm(StockReturnType::class, ['player' => $preselected, 'quantity' => 1, 'location' => $location], ['holdings' => $holders]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{player: Player, quantity: int, location: Location, condition: StockCondition, comment: ?string} $values */
            $values = $form->getData();
            $playerId = $values['player']->getId();
            try {
                $recorder->recordReturn(new StockReturn($values['player'], $variant, $values['quantity'], $values['location'], $values['condition'], $this->nullIfBlank($values['comment'] ?? null)));
            } catch (StockException $e) {
                $form->addError(new FormError($e->getMessage()));

                return $this->page($variant, $form, 'stock/loan/return.html.twig', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', 'Retour enregistré.');

            return $this->redirectToRoute('player_show', ['id' => $playerId]);
        }

        return $this->page($variant, $form, 'stock/loan/return.html.twig', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function page(Variant $variant, FormInterface $form, string $template, int $status): Response
    {
        return $this->render($template, ['variant' => $variant, 'form' => $form], new Response(status: $status));
    }
}
