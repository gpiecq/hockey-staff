<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Repository\ItemRepository;
use App\Repository\StockMovementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('STOCK_VIEW')]
final class ItemController extends AbstractController
{
    #[Route('/stock/pieces/{code}', name: 'stock_item_show', requirements: ['code' => '[A-Za-z0-9-]+'], methods: ['GET'])]
    public function show(string $code, ItemRepository $items, StockMovementRepository $movements): Response
    {
        $item = $items->findOneByCodeWithHolder($code) ?? throw $this->createNotFoundException('Pièce introuvable.');

        return $this->render('stock/item/show.html.twig', [
            'item' => $item,
            'movements' => $movements->findByItem($item),
        ]);
    }
}
