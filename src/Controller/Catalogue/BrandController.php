<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Entity\Brand;
use App\Form\BrandType;
use App\Pagination\Page;
use App\Repository\BrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marques')]
#[IsGranted('CATALOGUE_VIEW')]
final class BrandController extends AbstractController
{
    #[Route('', name: 'catalogue_brand_index', methods: ['GET'])]
    public function index(Request $request, BrandRepository $brands): Response
    {
        $qb = $brands->createQueryBuilder('s')->orderBy('s.name')->addOrderBy('s.id');

        return $this->render('catalogue/brand/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'catalogue_brand_new', methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BrandType::class, new Brand());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $brand = $form->getData();
            \assert($brand instanceof Brand);
            $em->persist($brand);
            $em->flush();
            $this->addFlash('success', 'Marque créée.');

            return $this->redirectToRoute('catalogue_brand_index');
        }

        return $this->render('catalogue/brand/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvelle marque',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'catalogue_brand_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function edit(Brand $brand, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BrandType::class, $brand);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Marque modifiée.');

            return $this->redirectToRoute('catalogue_brand_index');
        }

        return $this->render('catalogue/brand/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier la marque '.$brand->getName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'catalogue_brand_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function delete(Brand $brand, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('brand_delete_'.$brand->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $em->remove($brand);
            $em->flush();
            $this->addFlash('success', 'Marque supprimée.');

            return $this->redirectToRoute('catalogue_brand_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer la marque',
            'name' => $brand->getName(),
            'token_id' => 'brand_delete_'.$brand->getId(),
            'cancel_route' => 'catalogue_brand_index',
        ]);
    }
}
