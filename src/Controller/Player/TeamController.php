<?php

declare(strict_types=1);

namespace App\Controller\Player;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\RosterEntry;
use App\Entity\Season;
use App\Entity\Team;
use App\Form\RosterEntryType;
use App\Form\TeamType;
use App\Pagination\Page;
use App\Player\RosterCopier;
use App\Repository\RosterEntryRepository;
use App\Repository\SeasonRepository;
use App\Repository\TeamRepository;
use App\Repository\TeamSeasonRepository;
use App\Security\Voter\PlayerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/equipes')]
final class TeamController extends AbstractController
{
    public function __construct(
        private readonly CurrentSeasonProvider $currentSeason,
        private readonly SeasonRepository $seasons,
        private readonly TeamSeasonRepository $teamSeasons,
        private readonly RosterEntryRepository $entries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'team_index', methods: ['GET'])]
    #[IsGranted(PlayerVoter::VIEW)]
    public function index(Request $request, TeamRepository $teams): Response
    {
        $qb = $teams->createQueryBuilder('t')->orderBy('t.name')->addOrderBy('t.id');

        return $this->render('player/team/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'team_new', methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function new(Request $request): Response
    {
        $form = $this->createForm(TeamType::class, new Team());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $team = $form->getData();
            \assert($team instanceof Team);
            $this->em->persist($team);
            $this->em->flush();
            $this->addFlash('success', 'Équipe créée.');

            return $this->redirectToRoute('team_index');
        }

        return $this->render('player/team/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvelle équipe',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'team_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function edit(Team $team, Request $request): Response
    {
        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Équipe modifiée.');

            return $this->redirectToRoute('team_index');
        }

        return $this->render('player/team/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier l\'équipe '.$team->getName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'team_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function delete(Team $team, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'team_delete_'.$team->getId());
            $this->em->remove($team);
            $this->em->flush();
            $this->addFlash('success', 'Équipe supprimée.');

            return $this->redirectToRoute('team_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer l\'équipe',
            'name' => $team->getName(),
            'token_id' => 'team_delete_'.$team->getId(),
            'cancel_route' => 'team_index',
        ]);
    }

    #[Route('/{id}/effectif', name: 'team_roster', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PlayerVoter::VIEW)]
    public function roster(Team $team, Request $request): Response
    {
        $season = $this->resolveSeason($request);
        $form = null;
        if (null !== $season && $this->isGranted(PlayerVoter::MANAGE)) {
            $form = $this->createForm(RosterEntryType::class, null, [
                'action' => $this->generateUrl('team_roster_add', ['id' => $team->getId(), 'saison' => $season->getId()]),
            ]);
        }

        return $this->renderRoster($team, $season, $form);
    }

    #[Route('/{id}/effectif/ajouter', name: 'team_roster_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function add(Team $team, Request $request): Response
    {
        $season = $this->resolveSeason($request) ?? throw $this->createNotFoundException('Aucune saison.');
        $form = $this->createForm(RosterEntryType::class, null, [
            'action' => $this->generateUrl('team_roster_add', ['id' => $team->getId(), 'saison' => $season->getId()]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderRoster($team, $season, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{player: \App\Entity\Player, number: int|null} $data */
        $data = $form->getData();
        $teamSeason = $this->teamSeasons->findOrCreate($team, $season);
        if (null !== $teamSeason->getId() && $this->entries->exists($teamSeason, $data['player'])) {
            $this->addFlash('error', 'Ce joueur est déjà dans l\'effectif.');
        } else {
            $this->em->persist(new RosterEntry($teamSeason, $data['player'], $data['number']));
            $this->em->flush();
            $this->addFlash('success', 'Joueur ajouté à l\'effectif.');
        }

        return $this->redirectToRoster($team, $season);
    }

    #[Route('/{id}/effectif/{entry}/retirer', name: 'team_roster_remove', requirements: ['id' => '\d+', 'entry' => '\d+'], methods: ['POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function remove(Team $team, RosterEntry $entry, Request $request): Response
    {
        $this->checkCsrf($request, 'roster_remove_'.$entry->getId());
        $teamSeason = $entry->getTeamSeason();
        if ($teamSeason->getTeam()->getId() !== $team->getId()) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($entry);
        $this->em->flush();
        $this->addFlash('success', 'Joueur retiré de l\'effectif.');

        return $this->redirectToRoster($team, $teamSeason->getSeason());
    }

    #[Route('/{id}/effectif/reprendre', name: 'team_roster_copy', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(PlayerVoter::MANAGE)]
    public function copy(Team $team, Request $request, RosterCopier $copier): Response
    {
        $this->checkCsrf($request, 'roster_copy_'.$team->getId());
        $season = $this->resolveSeason($request) ?? throw $this->createNotFoundException('Aucune saison.');

        $teamSeason = $this->teamSeasons->findOrCreate($team, $season);
        $this->em->flush();
        $count = $copier->copyFromPrevious($teamSeason);
        $this->addFlash('success', \sprintf('%d joueur(s) repris de la saison précédente.', $count));

        return $this->redirectToRoster($team, $season);
    }

    private function resolveSeason(Request $request): ?Season
    {
        $id = $request->query->getInt('saison');
        if ($id > 0) {
            return $this->seasons->find($id) ?? throw $this->createNotFoundException('Saison inconnue.');
        }

        return $this->currentSeason->get();
    }

    /** @param FormInterface<mixed>|null $form */
    private function renderRoster(Team $team, ?Season $season, ?FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $teamSeason = null !== $season ? $this->teamSeasons->findOneFor($team, $season) : null;

        return $this->render('player/team/roster.html.twig', [
            'team' => $team,
            'season' => $season,
            'seasons' => $this->seasons->findBy([], ['startsOn' => 'DESC']),
            'entries' => null !== $teamSeason ? $this->entries->findForTeamSeason($teamSeason) : [],
            'form' => $form,
        ], new Response(status: $status));
    }

    private function redirectToRoster(Team $team, Season $season): Response
    {
        return $this->redirectToRoute('team_roster', ['id' => $team->getId(), 'saison' => $season->getId()]);
    }

    private function checkCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
