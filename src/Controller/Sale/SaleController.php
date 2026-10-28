<?php

declare(strict_types=1);

namespace App\Controller\Sale;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Sale;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Form\Sale\SaleLineMapper;
use App\Form\Sale\SaleType;
use App\Pagination\Page;
use App\Repository\ItemRepository;
use App\Repository\SaleRepository;
use App\Stock\Input\StockSale;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('SALE_MANAGE')]
#[Route('/ventes')]
final class SaleController extends AbstractController
{
    #[Route('', name: 'sale_index', methods: ['GET'])]
    public function index(Request $request, SaleRepository $sales): Response
    {
        $page = Page::fromQuery($sales->listQuery(), $request->query->getString('page'));
        /** @var list<Sale> $items */
        $items = $page->items;

        return $this->render('sale/index.html.twig', ['page' => $page, 'counts' => $sales->pieceCounts($items)]);
    }

    #[Route('/new', name: 'sale_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ItemRepository $items, SaleLineMapper $mapper, StockMovementRecorder $recorder): Response
    {
        $form = $this->createForm(SaleType::class, $this->initialData($request, $em, $items));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $lines = $mapper->map($form->get('lines'));
            if (null !== $lines) {
                /** @var array{location: Location, buyer: string, player: ?Player, comment: ?string} $values */
                $values = $form->getData();
                $comment = trim($values['comment'] ?? '');
                try {
                    $operation = $recorder->recordSale(new StockSale($values['location'], $values['buyer'], $lines, $values['player'] ?? null, '' === $comment ? null : $comment));
                } catch (StockException $e) {
                    // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point, rendu en lecture seule.
                    $form->addError(new FormError($e->getMessage()));

                    return $this->render('sale/form.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                $this->addFlash('success', 'Vente enregistrée.');

                return $this->redirectToRoute('sale_show', ['id' => $operation->getSale()?->getId()]);
            }
        }

        return $this->render('sale/form.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'sale_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, SaleRepository $sales): Response
    {
        $sale = $sales->findForShow($id) ?? throw $this->createNotFoundException('Vente introuvable.');

        return $this->render('sale/show.html.twig', ['sale' => $sale]);
    }

    /**
     * `?variant={id}` préremplit une ligne sur la première pièce d'occasion en stock (plus petit code) et son lieu.
     *
     * @return array<string, mixed>
     */
    private function initialData(Request $request, EntityManagerInterface $em, ItemRepository $items): array
    {
        $empty = ['lines' => [['itemCode' => null, 'discount' => null, 'finalPrice' => null]]];
        $variantId = $request->query->getString('variant');
        $variant = ctype_digit($variantId) ? $em->find(Variant::class, (int) $variantId) : null;
        if (!$variant instanceof Variant) {
            return $empty;
        }
        $first = array_find($items->findInStockByVariants([$variant]), static fn (Item $item): bool => ItemCondition::New !== $item->getCondition());

        return null === $first ? $empty : ['location' => $first->getLocation(), 'lines' => [['itemCode' => $first->getCode(), 'discount' => null, 'finalPrice' => null]]];
    }
}
