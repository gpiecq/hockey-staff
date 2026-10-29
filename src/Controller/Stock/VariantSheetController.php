<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Repository\PlayerHoldingRepository;
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
    public function show(int $id, EntityManagerInterface $em, PlayerHoldingRepository $holdings, SeasonPriceRepository $prices, CurrentSeasonProvider $seasons): Response
    {
        $variant = $em->find(Variant::class, $id) ?? throw $this->createNotFoundException('Variante introuvable.');

        /** @var list<StockLevel> $levels */
        $levels = $em->getRepository(StockLevel::class)->findBy(['variant' => $variant]);

        // Stock par lieu : niveaux ventilés par état (équipement), quantités (consommable).
        $locations = [];
        foreach ($levels as $level) {
            if ($level->getQuantity() > 0) {
                $name = $level->getLocation()->getName();
                $locations[$name]['conditions'][$level->getCondition()->value] = ($locations[$name]['conditions'][$level->getCondition()->value] ?? 0) + $level->getQuantity();
                $locations[$name]['quantity'] = ($locations[$name]['quantity'] ?? 0) + $level->getQuantity();
            }
        }
        ksort($locations);
        // États dans l'ordre Neuf, Bon, Usé, Abîmé.
        foreach ($locations as $name => $data) {
            $conditions = [];
            foreach (StockCondition::cases() as $condition) {
                if (isset($data['conditions'][$condition->value])) {
                    $conditions[$condition->label()] = $data['conditions'][$condition->value];
                }
            }
            $locations[$name]['conditions'] = $conditions;
        }

        // Occasion vendable : niveaux d'équipement d'état autre que Neuf, en quantité, par lieu puis état.
        $secondHand = array_values(array_filter(
            $levels,
            static fn (StockLevel $level): bool => ProductKind::Equipment === $variant->getProduct()?->getKind()
                && StockCondition::New !== $level->getCondition() && $level->getQuantity() > 0
                && $level->getLocation()->isActive(),
        ));
        $rank = array_flip(array_map(static fn (StockCondition $condition): string => $condition->value, StockCondition::cases()));
        usort($secondHand, static fn (StockLevel $a, StockLevel $b): int => [$a->getLocation()->getName(), $rank[$a->getCondition()->value]] <=> [$b->getLocation()->getName(), $rank[$b->getCondition()->value]]);

        // Lieu à préremplir (Transfert, Sortie) : le seul lieu actif qui contient du stock de la variante.
        $activeLocationIds = [];
        foreach ($levels as $level) {
            $locationId = $level->getLocation()->getId();
            if (null !== $locationId && $level->getQuantity() > 0 && $level->getLocation()->isActive()) {
                $activeLocationIds[$locationId] = true;
            }
        }
        $singleLocationId = 1 === \count($activeLocationIds) ? array_key_first($activeLocationIds) : null;

        $season = $seasons->get();

        return $this->render('stock/variant/show.html.twig', [
            'variant' => $variant,
            'product' => $variant->getProduct(),
            'season' => $season,
            'price' => null === $season ? null : $prices->findOneBy(['variant' => $variant, 'season' => $season]),
            'locations' => $locations,
            'holders' => $holdings->findByVariant($variant),
            'secondHand' => $secondHand,
            'singleLocationId' => $singleLocationId,
        ]);
    }
}
