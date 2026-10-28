<?php

declare(strict_types=1);

namespace App\Controller\Sale;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Sale;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\ItemStatus;
use App\Form\Sale\SaleLineMapper;
use App\Form\Sale\SaleType;
use App\Pagination\Page;
use App\Repository\ItemRepository;
use App\Repository\SaleRepository;
use App\Sale\SalePreviewer;
use App\Stock\Input\StockSale;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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

        return $this->render('sale/index.html.twig', ['page' => $page, 'summaries' => $sales->summaries($items)]);
    }

    #[Route('/new', name: 'sale_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ItemRepository $items, SaleLineMapper $mapper, StockMovementRecorder $recorder, SalePreviewer $previewer): Response
    {
        $form = $this->createForm(SaleType::class, $this->initialData($request, $em, $items));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $request->request->has('preview')) {
            // « Calculer » : rien n'est enregistré ; formulaire réaffiché avec les données saisies, sans erreurs de validation.
            return $this->renderForm($this->createForm(SaleType::class, $form->getData()), $items, $previewer, Response::HTTP_OK);
        }

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

                    return $this->renderForm($form, $items, $previewer, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('success', 'Vente enregistrée.');

                return $this->redirectToRoute('sale_show', ['id' => $operation->getSale()?->getId()]);
            }
        }

        return $this->renderForm($form, $items, $previewer, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'sale_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, SaleRepository $sales): Response
    {
        $sale = $sales->findForShow($id) ?? throw $this->createNotFoundException('Vente introuvable.');

        return $this->render('sale/show.html.twig', ['sale' => $sale]);
    }

    /**
     * Formulaire avec l'aperçu de prix de chaque ligne reconnue et la liste des pièces d'occasion vendables (datalist, tous lieux).
     *
     * @param FormInterface<mixed> $form
     */
    private function renderForm(FormInterface $form, ItemRepository $items, SalePreviewer $previewer, int $status): Response
    {
        $lines = $form->get('lines')->getData();
        $lines = \is_array($lines) ? $lines : [];
        $previews = $previewer->preview($lines);

        return $this->render('sale/form.html.twig', [
            'form' => $form,
            'previews' => $previews,
            'totals' => $previewer->totals($lines, $previews),
            'sellable' => $items->findSecondHandInStock(),
        ], new Response(status: $status));
    }

    /**
     * `?item={code}` préremplit une ligne sur cette pièce et son lieu, si elle est vendable (occasion en stock, lieu actif) ;
     * sinon `?variant={id}` préremplit la première pièce d'occasion en stock (plus petit code) et son lieu.
     *
     * @return array<string, mixed>
     */
    private function initialData(Request $request, EntityManagerInterface $em, ItemRepository $items): array
    {
        $empty = ['lines' => [['itemCode' => null, 'discount' => null, 'finalPrice' => null]]];
        $code = $request->query->getString('item');
        $item = '' === trim($code) ? null : $items->findOneByCode($code);
        if (null === $item) {
            $variantId = $request->query->getString('variant');
            $variant = ctype_digit($variantId) ? $em->find(Variant::class, (int) $variantId) : null;
            $item = $variant instanceof Variant
                ? array_find($items->findInStockByVariants([$variant]), static fn (Item $candidate): bool => ItemCondition::New !== $candidate->getCondition())
                : null;
        }
        if (null === $item || ItemStatus::InStock !== $item->getStatus() || ItemCondition::New === $item->getCondition() || !$item->getLocation()->isActive()) {
            return $empty;
        }

        return ['location' => $item->getLocation(), 'lines' => [['itemCode' => $item->getCode(), 'discount' => null, 'finalPrice' => null]]];
    }
}
