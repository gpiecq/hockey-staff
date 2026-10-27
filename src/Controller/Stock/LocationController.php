<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Form\LocationType;
use App\Pagination\Page;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/lieux')]
#[IsGranted('LOCATION_MANAGE')]
final class LocationController extends AbstractController
{
    #[Route('', name: 'stock_location_index', methods: ['GET'])]
    public function index(Request $request, LocationRepository $locations): Response
    {
        $qb = $locations->createQueryBuilder('l')->orderBy('l.name')->addOrderBy('l.id');

        return $this->render('stock/location/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'stock_location_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(LocationType::class, new Location());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $location = $form->getData();
            \assert($location instanceof Location);
            $em->persist($location);
            $em->flush();
            $this->addFlash('success', 'Lieu créé.');

            return $this->redirectToRoute('stock_location_index');
        }

        return $this->render('stock/location/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau lieu',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'stock_location_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Location $location, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Lieu modifié.');

            return $this->redirectToRoute('stock_location_index');
        }

        return $this->render('stock/location/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier le lieu '.$location->getName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'stock_location_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Location $location, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('location_delete_'.$location->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $em->remove($location);
            $em->flush();
            $this->addFlash('success', 'Lieu supprimé.');

            return $this->redirectToRoute('stock_location_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer le lieu',
            'name' => $location->getName(),
            'token_id' => 'location_delete_'.$location->getId(),
            'cancel_route' => 'stock_location_index',
        ]);
    }
}
