<?php

declare(strict_types=1);

namespace App\Controller\Player;

use App\Entity\Player;
use App\Form\PlayerType;
use App\Pagination\Page;
use App\Repository\ItemRepository;
use App\Repository\PlayerRepository;
use App\Repository\RosterEntryRepository;
use App\Repository\SeasonRepository;
use App\Repository\TeamRepository;
use App\Repository\TeamSeasonRepository;
use App\Security\Voter\PlayerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/joueurs')]
final class PlayerController extends AbstractController
{
    #[Route('', name: 'player_index', methods: ['GET'])]
    #[IsGranted(PlayerVoter::VIEW)]
    public function index(
        Request $request,
        PlayerRepository $players,
        TeamRepository $teams,
        SeasonRepository $seasons,
        TeamSeasonRepository $teamSeasons,
        RosterEntryRepository $entries,
    ): Response {
        $q = $request->query->getString('q');
        $team = $request->query->getInt('equipe') > 0 ? $teams->find($request->query->getInt('equipe')) : null;
        $season = $request->query->getInt('saison') > 0 ? $seasons->find($request->query->getInt('saison')) : null;

        $numbers = null;
        if (null !== $team && null !== $season) {
            $teamSeason = $teamSeasons->findOneFor($team, $season);
            $numbers = null !== $teamSeason ? $entries->numbersByPlayer($teamSeason) : [];
        }

        return $this->render('player/player/index.html.twig', [
            'page' => Page::fromQuery($players->search($q, $team, $season), $request->query->getString('page')),
            'q' => $q,
            'teams' => $teams->findAllOrdered(),
            'seasons' => $seasons->findBy([], ['startsOn' => 'DESC']),
            'team' => $team,
            'season' => $season,
            'numbers' => $numbers,
        ]);
    }

    #[Route('/new', name: 'player_new', methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PlayerType::class, new Player());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $player = $form->getData();
            \assert($player instanceof Player);
            $em->persist($player);
            $em->flush();
            $this->addFlash('success', 'Joueur créé.');

            return $this->redirectToRoute('player_index');
        }

        return $this->render('player/player/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau joueur',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'player_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PlayerVoter::VIEW)]
    public function show(Player $player): Response
    {
        return $this->render('player/player/show.html.twig', ['player' => $player]);
    }

    #[Route('/{id}/edit', name: 'player_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function edit(Player $player, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PlayerType::class, $player);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Joueur modifié.');

            return $this->redirectToRoute('player_show', ['id' => $player->getId()]);
        }

        return $this->render('player/player/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier le joueur '.$player->getFullName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'player_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function delete(Player $player, Request $request, EntityManagerInterface $em, ItemRepository $items): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('player_delete_'.$player->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ([] !== $items->findLoanedByPlayer($player)) {
                $this->addFlash('error', 'Ce joueur détient encore du matériel prêté : enregistrez d\'abord le retour.');

                return $this->redirectToRoute('player_index');
            }

            $em->remove($player);
            $em->flush();
            $this->addFlash('success', 'Joueur supprimé.');

            return $this->redirectToRoute('player_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer le joueur',
            'name' => $player->getFullName(),
            'token_id' => 'player_delete_'.$player->getId(),
            'cancel_route' => 'player_index',
        ]);
    }
}
