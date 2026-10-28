<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Catalogue\CatalogueSearch;
use App\Catalogue\CatalogueSearchCriteria;
use App\Catalogue\CodeResolver;
use App\Catalogue\CurrentSeasonProvider;
use App\Catalogue\ProductKindResolver;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Product;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Form\CatalogueFilterType;
use App\Form\ProductPriceType;
use App\Form\ProductType;
use App\Repository\SeasonPriceRepository;
use App\Repository\VariantRepository;
use App\Stock\StockPresence;
use App\Stock\StockQuery;
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
    public function index(Request $request, CatalogueSearch $search, CurrentSeasonProvider $currentSeason, CodeResolver $resolver): Response
    {
        $form = $this->createForm(CatalogueFilterType::class);
        $form->handleRequest($request);

        $data = $form->isSubmitted() ? $form->getData() : [];
        \assert(\is_array($data));
        $query = \is_string($data['q'] ?? null) ? trim($data['q']) : '';

        // Un code scanné (EAN, HV-…, HS-…) mène directement à la fiche produit ou à la fiche pièce.
        $found = '' !== $query ? $resolver->resolve($query) : null;
        if ($found instanceof Variant && null !== $found->getProduct()) {
            return $this->redirectToRoute('catalogue_product_show', ['id' => $found->getProduct()->getId()]);
        }
        if ($found instanceof Item && $this->isGranted('STOCK_VIEW')) {
            return $this->redirectToRoute('stock_item_show', ['code' => $found->getCode()]);
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
            includeArchived: true === ($data['includeArchived'] ?? false),
            withoutValidEan: true === ($data['sansEan'] ?? false),
            withoutCategory: true === ($data['sansCategorie'] ?? false),
            missingPriceSeasonId: true === ($data['sansTarif'] ?? false) ? $currentSeason->get()?->getId() : null,
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
        StockQuery $stock,
    ): Response {
        $season = $currentSeason->get();

        return $this->render('catalogue/product/show.html.twig', [
            'stock' => $stock->byVariant($product),
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
    public function edit(Product $product, Request $request, EntityManagerInterface $em, ProductKindResolver $kinds, StockPresence $stockPresence): Response
    {
        $locked = $stockPresence->productHasStock($product);
        $hadCategory = null !== $product->getCategory();
        $originalKind = $product->getKind();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Première catégorisation : le type par défaut de la catégorie s'applique, sauf si l'utilisateur l'a modifié.
            $category = $product->getCategory();
            if (!$locked && !$hadCategory && null !== $category && $product->getKind() === $originalKind) {
                $product->setKind($kinds->defaultFor($category));
            }
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

    /** Saisie du tarif au niveau produit : ne remplit que les variantes actives sans prix club, n'écrase aucun prix renseigné. */
    #[Route('/{id}/tarif', name: 'catalogue_product_price', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function price(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        CurrentSeasonProvider $currentSeason,
        VariantRepository $variants,
        SeasonPriceRepository $prices,
    ): Response {
        $season = $currentSeason->get();
        $cancelUrl = $this->generateUrl('catalogue_product_show', ['id' => $product->getId()]);
        $title = 'Tarif produit · '.$product->getDesignation();

        if (null === $season) {
            return $this->render('catalogue/product/price.html.twig', ['form' => null, 'title' => $title, 'season' => null, 'cancel_url' => $cancelUrl]);
        }

        $form = $this->createForm(ProductPriceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            \assert(\is_array($data));
            $club = $data['clubPriceTtc'];
            $public = $data['publicPriceTtc'] ?? null;
            \assert(\is_int($club) && (null === $public || \is_int($public)));

            $existing = $prices->indexByVariantForProduct($product, $season);
            $applied = 0;
            foreach ($variants->findLiveByProduct($product) as $variant) {
                if (!$variant->isActive()) {
                    continue;
                }
                $variantId = $variant->getId();
                $price = null === $variantId ? null : ($existing[$variantId] ?? null);
                if (null === $price) {
                    $price = (new SeasonPrice())->setVariant($variant)->setSeason($season)->setPublicPriceTtc($public);
                    $em->persist($price);
                } elseif (null !== $price->getClubPriceTtc()) {
                    continue;
                } elseif (null === $price->getPublicPriceTtc()) {
                    $price->setPublicPriceTtc($public);
                }
                $price->setClubPriceTtc($club);
                ++$applied;
            }
            $em->flush();
            $this->addFlash('success', \sprintf('Tarif appliqué à %d variante(s).', $applied));

            return $this->redirect($cancelUrl);
        }

        return $this->render('catalogue/product/price.html.twig', [
            'form' => $form,
            'title' => $title,
            'season' => $season,
            'cancel_url' => $cancelUrl,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/{id}/delete', name: 'catalogue_product_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('CATALOGUE_EDIT')]
    public function delete(Product $product, Request $request, EntityManagerInterface $em, StockPresence $stockPresence): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('product_delete_'.$product->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($stockPresence->productHasMovements($product)) {
                $this->addFlash('error', 'Ce produit a des mouvements de stock : il ne peut pas être supprimé.');

                return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
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
