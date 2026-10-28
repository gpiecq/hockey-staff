<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Entity\Location;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\StockMovementType;
use App\Form\Stock\OperationFilterType;
use App\Pagination\Page;
use App\Repository\StockOperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stock/operations')]
final class OperationController extends AbstractController
{
    #[Route('', name: 'stock_operation_index', methods: ['GET'])]
    #[IsGranted('STOCK_VIEW')]
    public function index(Request $request, StockOperationRepository $operations, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(OperationFilterType::class);
        $form->handleRequest($request);
        $data = $form->isSubmitted() && $form->isValid() ? $form->getData() : [];
        \assert(\is_array($data));

        $type = $data['type'] ?? null;
        $location = $data['lieu'] ?? null;
        $from = $data['du'] ?? null;
        $to = $data['au'] ?? null;
        $variantId = $data['variante'] ?? null;
        $variant = \is_string($variantId) && ctype_digit($variantId) ? $em->getRepository(Variant::class)->find((int) $variantId) : null;

        $page = Page::fromQuery(
            $operations->search(
                $type instanceof StockMovementType ? $type : null,
                $location instanceof Location ? $location : null,
                $from instanceof \DateTimeImmutable ? $from : null,
                $to instanceof \DateTimeImmutable ? $to : null,
                $variant,
            ),
            $request->query->getString('page'),
        );
        /** @var list<StockOperation> $items */
        $items = $page->items;

        return $this->render('stock/operation/index.html.twig', [
            'form' => $form,
            'page' => $page,
            'counts' => $operations->lineCounts($items),
            'variant' => $variant,
        ]);
    }

    #[Route('/{id}', name: 'stock_operation_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('STOCK_VIEW')]
    public function show(int $id, StockOperationRepository $operations): Response
    {
        $operation = $operations->findWithPeople($id) ?? throw $this->createNotFoundException('Opération introuvable.');

        return $this->render('stock/operation/show.html.twig', ['operation' => $operation]);
    }
}
