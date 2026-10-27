<?php

declare(strict_types=1);

namespace App\Controller\Catalogue;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Product;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
use App\Form\SeasonPriceType;
use App\Form\VariantType;
use App\Repository\SeasonPriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/catalogue')]
#[IsGranted('CATALOGUE_EDIT')]
final class VariantController extends AbstractController
{
    #[Route('/{id}/variantes/new', name: 'catalogue_variant_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Product $product, Request $request, EntityManagerInterface $em): Response
    {
        $variant = new Variant();
        $product->addVariant($variant);
        $form = $this->createForm(VariantType::class, $variant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($variant);
            $em->flush();
            $this->addFlash('success', 'Variante créée.');

            return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
        }

        return $this->renderForm($form, 'Nouvelle variante · '.$product->getDesignation(), $product);
    }

    #[Route('/variantes/{id}/edit', name: 'catalogue_variant_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Variant $variant, Request $request, EntityManagerInterface $em): Response
    {
        $product = $this->productOf($variant);
        $form = $this->createForm(VariantType::class, $variant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Variante modifiée.');

            return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
        }

        return $this->renderForm($form, 'Modifier la variante · '.$product->getDesignation(), $product);
    }

    /** GET : page de confirmation ; POST : suppression (douce) après contrôle du jeton CSRF. */
    #[Route('/variantes/{id}/delete', name: 'catalogue_variant_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Variant $variant, Request $request, EntityManagerInterface $em): Response
    {
        $product = $this->productOf($variant);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('variant_delete_'.$variant->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $em->remove($variant);
            $em->flush();
            $this->addFlash('success', 'Variante supprimée.');

            return $this->redirectToRoute('catalogue_product_show', ['id' => $product->getId()]);
        }

        return $this->render('catalogue/_confirm_delete.html.twig', [
            'title' => 'Supprimer la variante',
            'name' => $product->getDesignation().' · '.($variant->getLabel() ?: 'Unique'),
            'token_id' => 'variant_delete_'.$variant->getId(),
            'cancel_route' => 'catalogue_product_show',
            'cancel_params' => ['id' => $product->getId()],
        ]);
    }

    /** Ajout ou édition du tarif de la saison courante uniquement. */
    #[Route('/variantes/{id}/tarif', name: 'catalogue_variant_price', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function price(
        Variant $variant,
        Request $request,
        EntityManagerInterface $em,
        CurrentSeasonProvider $currentSeason,
        SeasonPriceRepository $prices,
    ): Response {
        $product = $this->productOf($variant);
        $season = $currentSeason->get();
        $title = 'Tarif · '.$product->getDesignation().' · '.($variant->getLabel() ?: 'Unique');
        $cancelUrl = $this->generateUrl('catalogue_product_show', ['id' => $product->getId()]);

        if (null === $season) {
            return $this->render('catalogue/variant/price.html.twig', ['form' => null, 'title' => $title, 'season' => null, 'cancel_url' => $cancelUrl]);
        }

        $price = $prices->findOneBy(['variant' => $variant, 'season' => $season])
            ?? (new SeasonPrice())->setVariant($variant)->setSeason($season);
        $form = $this->createForm(SeasonPriceType::class, $price);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($price);
            $em->flush();
            $this->addFlash('success', 'Tarif enregistré.');

            return $this->redirect($cancelUrl);
        }

        return $this->render('catalogue/variant/price.html.twig', [
            'form' => $form,
            'title' => $title,
            'season' => $season,
            'cancel_url' => $cancelUrl,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function productOf(Variant $variant): Product
    {
        // Le produit supprimé (filtre softdeleteable) fait échouer le chargement du proxy : 404, jamais 500.
        try {
            $product = $variant->getProduct();
            if (null === $product || null !== $product->getDeletedAt()) {
                throw $this->createNotFoundException();
            }
        } catch (EntityNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $product;
    }

    private function renderForm(FormInterface $form, string $title, Product $product): Response
    {
        return $this->render('catalogue/variant/form.html.twig', [
            'form' => $form,
            'title' => $title,
            'cancel_url' => $this->generateUrl('catalogue_product_show', ['id' => $product->getId()]),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
