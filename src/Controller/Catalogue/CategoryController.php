<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Pagination\Page;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/categories')]
#[IsGranted('CATALOGUE_VIEW')]
final class CategoryController extends AbstractController
{
    #[Route('', name: 'catalogue_category_index', methods: ['GET'])]
    public function index(Request $request, CategoryRepository $categories): Response
    {
        return $this->render('catalogue/category/index.html.twig', [
            'page' => Page::fromQuery($categories->createTreeQueryBuilder(), $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'catalogue_category_new', methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CategoryType::class, new Category());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $form->getData();
            \assert($category instanceof Category);
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Catégorie créée.');

            return $this->redirectToRoute('catalogue_category_index');
        }

        return $this->render('catalogue/category/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvelle catégorie',
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'catalogue_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function edit(Category $category, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Catégorie modifiée.');

            return $this->redirectToRoute('catalogue_category_index');
        }

        return $this->render('catalogue/category/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier la catégorie '.$category->getName(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
