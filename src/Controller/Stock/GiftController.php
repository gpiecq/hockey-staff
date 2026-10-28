<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Team;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\TrackingMode;
use App\Form\Stock\StockGiftType;
use App\Repository\ItemRepository;
use App\Repository\LocationRepository;
use App\Stock\Input\StockGift;
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
final class GiftController extends AbstractController
{
    use VariantFormScreen;

    #[Route('/stock/variantes/{id}/don', name: 'stock_gift_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(int $id, Request $request, EntityManagerInterface $em, ItemRepository $items, LocationRepository $locations, StockMovementRecorder $recorder): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');
        $serialized = TrackingMode::Serialized === $variant->getProduct()?->getTrackingMode();
        $location = $this->selectedLocation($request, $locations, 'stock_gift');
        $form = $this->createForm(StockGiftType::class, ['location' => $location, 'recipient' => 'player', 'quantity' => 1], [
            'counts' => $this->countsAt($items, $variant, $location),
            'serialized' => $serialized,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{location: Location, recipient: string, player: ?Player, team: ?Team, quantity?: int, condition?: ItemCondition, comment: ?string} $values */
            $values = $form->getData();
            $player = 'player' === $values['recipient'] ? $values['player'] : null;
            $team = 'team' === $values['recipient'] ? $values['team'] : null;
            if (null === $player && null === $team) {
                $form->addError(new FormError('Choisissez le joueur ou l\'équipe bénéficiaire.'));

                return $this->page($variant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            try {
                $recorder->recordGift(new StockGift(
                    $values['location'],
                    $variant,
                    $serialized ? 1 : ($values['quantity'] ?? 1),
                    $serialized ? ($values['condition'] ?? null) : null,
                    $player,
                    $team,
                    $this->nullIfBlank($values['comment'] ?? null),
                ));
            } catch (StockException $e) {
                // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point.
                $form->addError(new FormError($e->getMessage()));

                return $this->page($variant, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('success', 'Don enregistré.');

            return $this->redirectToRoute('stock_variant_show', ['id' => $id]);
        }

        return $this->page($variant, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function page(Variant $variant, FormInterface $form, int $status): Response
    {
        return $this->render('stock/gift/form.html.twig', ['variant' => $variant, 'form' => $form], new Response(status: $status));
    }
}
