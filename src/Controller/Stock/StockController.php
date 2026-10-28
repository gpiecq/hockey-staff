<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Repository\CategoryRepository;
use App\Repository\LocationRepository;
use App\Stock\StockQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_VIEW')]
final class StockController extends AbstractController
{
    #[Route('/stock', name: 'stock_index', methods: ['GET'])]
    public function index(Request $request, StockQuery $stock, LocationRepository $locations, CategoryRepository $categories): Response
    {
        $locationId = $this->idParam($request, 'lieu');
        $location = null === $locationId ? null : $locations->find($locationId);
        $categoryId = $this->idParam($request, 'categorie');
        $search = trim($request->query->getString('q'));

        $allLocations = $locations->findBy([], ['name' => 'ASC', 'id' => 'ASC']);

        return $this->render('stock/index.html.twig', [
            'page' => $stock->rows($location, $categoryId, '' === $search ? null : $search, $request->query->getString('page')),
            'total' => $stock->totalValueHt($location),
            'location' => $location,
            'locations' => $allLocations,
            'locationNames' => array_combine(array_map(static fn (Location $l): int => (int) $l->getId(), $allLocations), array_map(static fn (Location $l): string => $l->getName(), $allLocations)),
            'categories' => $categories->createTreeQueryBuilder()->getQuery()->getResult(),
            'categoryId' => $categoryId,
            'search' => $search,
        ]);
    }

    private function idParam(Request $request, string $name): ?int
    {
        $raw = $request->query->getString($name);

        return '' !== $raw && ctype_digit($raw) ? (int) $raw : null;
    }
}
