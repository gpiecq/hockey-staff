<?php

declare(strict_types=1);

namespace App\Controller\Player;

use App\Entity\PlayerHolding;
use App\Pagination\Page;
use App\Repository\PlayerHoldingRepository;
use App\Repository\SeasonRepository;
use App\Repository\TeamRepository;
use App\Security\Voter\PlayerVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LoanListController extends AbstractController
{
    #[Route('/prets', name: 'loan_index', methods: ['GET'])]
    #[IsGranted(PlayerVoter::VIEW)]
    public function index(Request $request, PlayerHoldingRepository $holdings, TeamRepository $teams, SeasonRepository $seasons): Response
    {
        $team = $request->query->getInt('equipe') > 0 ? $teams->find($request->query->getInt('equipe')) : null;
        $season = $request->query->getInt('saison') > 0 ? $seasons->find($request->query->getInt('saison')) : null;

        /** @var Page<PlayerHolding> $page */
        $page = Page::fromQuery($holdings->listQuery($team, $season), $request->query->getString('page'));

        return $this->render('player/loan/index.html.twig', [
            'page' => $page,
            'loanDates' => $holdings->loanDates($page->items),
            'teams' => $teams->findAllOrdered(),
            'seasons' => $seasons->findBy([], ['startsOn' => 'DESC']),
            'team' => $team,
            'season' => $season,
        ]);
    }
}
