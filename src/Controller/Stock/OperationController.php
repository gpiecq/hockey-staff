<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\StockOperation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stock/operations')]
final class OperationController extends AbstractController
{
    #[Route('/{id}', name: 'stock_operation_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('STOCK_VIEW')]
    public function show(StockOperation $operation): Response
    {
        return $this->render('stock/operation/show.html.twig', ['operation' => $operation]);
    }
}
