<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Repository\ItemRepository;
use App\Repository\SeasonPriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche d'une variante : point d'arrivée du futur scan de code-barres.
 */
#[IsGranted('STOCK_VIEW')]
final class VariantSheetController extends AbstractController
{
    #[Route('/stock/variantes/{id}', name: 'stock_variant_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em, ItemRepository $items, SeasonPriceRepository $prices, CurrentSeasonProvider $seasons): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');

        /** @var list<StockLevel> $levels */
        $levels = $em->getRepository(StockLevel::class)->findBy(['variant' => $variant]);

        // Stock par lieu : pièces ventilées par état (sérialisé), quantités (produit en quantité).
        $locations = [];
        $secondHand = [];
        foreach ($items->findInStockByVariants([$variant]) as $item) {
            $name = $item->getLocation()->getName();
            $locations[$name]['conditions'][$item->getCondition()->value] = ($locations[$name]['conditions'][$item->getCondition()->value] ?? 0) + 1;
            $locations[$name]['quantity'] = ($locations[$name]['quantity'] ?? 0) + 1;
            if (ItemCondition::New !== $item->getCondition()) {
                $secondHand[] = $item;
            }
        }
        foreach ($levels as $level) {
            if ($level->getQuantity() > 0) {
                $name = $level->getLocation()->getName();
                $locations[$name]['quantity'] = ($locations[$name]['quantity'] ?? 0) + $level->getQuantity();
            }
        }
        ksort($locations);

        $season = $seasons->get();
        $loaned = $items->findLoanedByVariant($variant);

        return $this->render('stock/variant/show.html.twig', [
            'variant' => $variant,
            'product' => $variant->getProduct(),
            'season' => $season,
            'price' => null === $season ? null : $prices->findOneBy(['variant' => $variant, 'season' => $season]),
            'locations' => $locations,
            'loaned' => $loaned,
            'secondHand' => $secondHand,
        ]);
    }
}
