<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Catalogue\CatalogueSearch;
use App\Catalogue\CatalogueSearchCriteria;
use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Form\CatalogueFilterType;
use App\Form\ProductType;
use App\Repository\SeasonPriceRepository;
use App\Repository\VariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/catalogue')]
#[IsGranted('CATALOGUE_VIEW')]
final class ProductController extends AbstractController
{
    #[Route('', name: 'catalogue_product_index', methods: ['GET'])]
    public function index(Request $request, CatalogueSearch $search): Response
    {
        $form = $this->createForm(CatalogueFilterType::class);
        $form->handleRequest($request);

        $data = $form->isSubmitted() ? $form->getData() : [];
        \assert(\is_array($data));
        $query = \is_string($data['q'] ?? null) ? trim($data['q']) : '';

        // Un EAN exact d'une variante vivante mène directement à sa fiche produit.
        if ('' !== $query && null !== ($variant = $search->findVariantByEan($query)) && null !== $variant->getProduct()) {
            return $this->redirectToRoute('catalogue_product_show', ['id' => $variant->getProduct()->getId()]);
        }

        $category = $data['category'] ?? null;
        $supplier = $data['supplier'] ?? null;
        $brand = $data['brand'] ?? null;
        $page = $search->search(new CatalogueSearchCriteria(
            query: '' === $query ? null : $query,
            categoryId: $category instanceof Category ? $category->getId() : null,
            supplierId: $supplier instanceof Supplier ? $supplier->getId() : null,
            brandId: $brand instanceof Brand ? $brand->getId() : null,
            includeInactive: true === ($data['includeInactive'] ?? false),
            page: ctype_digit($rawPage = $request->query->getString('page')) ? max(1, (int) $rawPage) : 1,
        ));

        // 200 même si un paramètre est invalide (valeur ignorée) : pas de 422 sur un formulaire GET
        return $this->render('catalogue/product/index.html.twig', ['form' => $form, 'page' => $page])
            ->setStatusCode(Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'catalogue_product_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Product $product,
        VariantRepository $variants,
        SeasonPriceRepository $prices,
        CurrentSeasonProvider $currentSeason,
    ): Response {
        $season = $currentSeason->get();

        return $this->render('catalogue/product/show.html.twig', [
            'product' => $product,
            'season' => $season,
            'variants' => $variants->findLiveByProduct($product),
            'prices' => null === $season ? [] : $prices->indexByVariantForProduct($product, $season),
        ]);
    }

    #[Route('/new', name: 'catalogue_product_new', methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProductType::class, new Product());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product = $form->getData();
            \assert($product instanceof Product);
            $em->persist($product);
            $em->flush();
            $this->addFlash('success', 'Produit créé.');

            return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
        }

        return $this->render('catalogue/product/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau produit',
            'cancel_url' => $this->generateUrl('catalogue_product_index'),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'catalogue_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function edit(Product $product, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Produit modifié.');

            return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
        }

        return $this->render('catalogue/product/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier le produit '.$product->getReference(),
            'cancel_url' => $this->generateUrl('catalogue_product_show', ['id' => $product->getId()]),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'catalogue_product_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function delete(Product $product, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('product_delete_'.$product->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $em->remove($product);
            $em->flush();
            $this->addFlash('success', 'Produit supprimé.');

            return $this->redirectToRoute('catalogue_product_index');
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer le produit',
            'name' => $product->getDesignation(),
            'token_id' => 'product_delete_'.$product->getId(),
            'cancel_route' => 'catalogue_product_show',
            'cancel_params' => ['id' => $product->getId()],
        ]);
    }
}
