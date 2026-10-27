<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Entity\Supplier;
use App\Form\SupplierType;
use App\Pagination\Page;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fournisseurs')]
#[IsGranted('CATALOGUE_VIEW')]
final class SupplierController extends AbstractController
{
    #[Route('', name: 'catalogue_supplier_index', methods: ['GET'])]
    public function index(Request $request, SupplierRepository $suppliers): Response
    {
        $qb = $suppliers->createQueryBuilder('s')->orderBy('s.name')->addOrderBy('s.id');

        return $this->render('catalogue/supplier/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'catalogue_supplier_new', methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SupplierType::class, new Supplier());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $supplier = $form->getData();
            \assert($supplier instanceof Supplier);
            $em->persist($supplier);
            $em->flush();
            $this->addFlash('success', 'Fournisseur créé.');

            return $this->redirectToRoute('catalogue_supplier_index');
        }

        return $this->render('catalogue/supplier/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau fournisseur',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'catalogue_supplier_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function edit(Supplier $supplier, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SupplierType::class, $supplier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Fournisseur modifié.');

            return $this->redirectToRoute('catalogue_supplier_index');
        }

        return $this->render('catalogue/supplier/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier le fournisseur '.$supplier->getName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'catalogue_supplier_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function delete(Supplier $supplier, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('supplier_delete_'.$supplier->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $em->remove($supplier);
            $em->flush();
            $this->addFlash('success', 'Fournisseur supprimé.');

            return $this->redirectToRoute('catalogue_supplier_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer le fournisseur',
            'name' => $supplier->getName(),
            'token_id' => 'supplier_delete_'.$supplier->getId(),
            'cancel_route' => 'catalogue_supplier_index',
        ]);
    }
}
