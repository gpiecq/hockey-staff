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
use App\Repository\SeasonPriceRepository;
use App\Repository\VariantRepository;
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
            page: max(1, $request->query->getInt('page', 1)),
        ));

        return $this->render('catalogue/product/index.html.twig', ['form' => $form, 'page' => $page]);
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
}
