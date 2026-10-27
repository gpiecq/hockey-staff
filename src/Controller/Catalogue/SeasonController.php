<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Season;
use App\Form\SeasonType;
use App\Pagination\Page;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/saisons')]
#[IsGranted('ROLE_VIEWER')]
final class SeasonController extends AbstractController
{
    #[Route('', name: 'catalogue_season_index', methods: ['GET'])]
    public function index(Request $request, SeasonRepository $seasons): Response
    {
        $qb = $seasons->createQueryBuilder('s')->orderBy('s.label', 'DESC')->addOrderBy('s.id', 'DESC');

        return $this->render('catalogue/season/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'catalogue_season_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $today = new \DateTimeImmutable('today');
        $form = $this->createForm(SeasonType::class, new Season('', $today, $today));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $season = $form->getData();
            \assert($season instanceof Season);
            $em->persist($season);
            $em->flush();
            $this->addFlash('success', 'Saison créée.');

            return $this->redirectToRoute('catalogue_season_index');
        }

        return $this->render('catalogue/season/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvelle saison',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'catalogue_season_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Season $season, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SeasonType::class, $season);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Saison modifiée.');

            return $this->redirectToRoute('catalogue_season_index');
        }

        return $this->render('catalogue/season/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier la saison '.$season->getLabel(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/current', name: 'catalogue_season_current', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function makeCurrent(Season $season, Request $request, CurrentSeasonProvider $current): Response
    {
        if (!$this->isCsrfTokenValid('season_current_'.$season->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $current->makeCurrent($season);
        $this->addFlash('success', 'La saison '.$season->getLabel().' est désormais la saison courante.');

        return $this->redirectToRoute('catalogue_season_index');
    }
}
