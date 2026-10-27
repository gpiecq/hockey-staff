<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalogue\CurrentSeasonProvider;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        CurrentSeasonProvider $currentSeason,
        ProductRepository $products,
        VariantRepository $variants,
        ImportRunRepository $imports,
    ): Response {
        $isManager = $this->isGranted('ROLE_MANAGER');
        $season = $currentSeason->get();

        return $this->render('dashboard/index.html.twig', [
            'season' => $season,
            'productCount' => $products->count([]),
            'variantCount' => $variants->countActiveOfLiveProducts(),
            'canImport' => $isManager,
            'missingCategoryCount' => $isManager ? $products->countWithoutCategory() : null,
            'missingPriceCount' => $isManager && null !== $season ? $products->countMissingClubPrice($season) : null,
            'lastImport' => $isManager ? $imports->findLatestNotCancelled() : null,
        ]);
    }
}
