<?php

declare(strict_types=1);

namespace App\Controller\Sale;

use App\Controller\Stock\VariantFormScreen;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Sale;
use App\Entity\StockLevel;
use App\Form\Sale\SaleLineMapper;
use App\Form\Sale\SaleLineType;
use App\Form\Sale\SaleType;
use App\Pagination\Page;
use App\Repository\LocationRepository;
use App\Repository\SaleRepository;
use App\Repository\StockLevelRepository;
use App\Sale\SalePreviewer;
use App\Stock\Input\StockSale;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
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
    use VariantFormScreen;

    #[Route('', name: 'sale_index', methods: ['GET'])]
    public function index(Request $request, SaleRepository $sales): Response
    {
        $page = Page::fromQuery($sales->listQuery(), $request->query->getString('page'));
        /** @var list<Sale> $items */
        $items = $page->items;

        return $this->render('sale/index.html.twig', ['page' => $page, 'summaries' => $sales->summaries($items)]);
    }

    #[Route('/new', name: 'sale_new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocationRepository $locations, StockLevelRepository $levels, SaleLineMapper $mapper, StockMovementRecorder $recorder, SalePreviewer $previewer): Response
    {
        [$location, $line] = $this->prefill($request, $locations, $levels);
        $sellable = null === $location ? [] : $levels->findSecondHand($location);
        $data = ['location' => $location, 'lines' => [['stock' => $line, 'quantity' => 1, 'discount' => null, 'finalPrice' => null]]];
        $form = $this->createForm(SaleType::class, $data, ['sellable' => $sellable]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $request->request->has('preview')) {
            // « Calculer » : rien n'est enregistré ; formulaire réaffiché avec les données saisies, sans erreurs de validation.
            return $this->renderForm($this->createForm(SaleType::class, $form->getData(), ['sellable' => $sellable]), $previewer, Response::HTTP_OK);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $lines = $mapper->map($form->get('lines'));
            if (null !== $lines) {
                /** @var array{location: Location, buyer: string, player: ?Player, comment: ?string} $values */
                $values = $form->getData();
                try {
                    $operation = $recorder->recordSale(new StockSale($values['location'], $values['buyer'], $lines, $values['player'] ?? null, $this->nullIfBlank($values['comment'] ?? null)));
                } catch (StockException $e) {
                    // R6 : l'EntityManager peut être fermé ; aucune écriture après ce point, rendu en lecture seule.
                    $form->addError(new FormError($e->getMessage()));

                    return $this->renderForm($form, $previewer, Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('success', 'Vente enregistrée.');

                return $this->redirectToRoute('sale_show', ['id' => $operation->getSale()?->getId()]);
            }
        }

        return $this->renderForm($form, $previewer, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'sale_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, SaleRepository $sales): Response
    {
        $sale = $sales->findForShow($id) ?? throw $this->createNotFoundException('Vente introuvable.');

        return $this->render('sale/show.html.twig', ['sale' => $sale]);
    }

    /**
     * Formulaire avec l'aperçu de prix (unitaire et total) de chaque ligne reconnue.
     *
     * @param FormInterface<mixed> $form
     */
    private function renderForm(FormInterface $form, SalePreviewer $previewer, int $status): Response
    {
        $lines = $form->get('lines')->getData();
        $lines = \is_array($lines) ? $lines : [];
        $previews = $previewer->preview($lines);

        return $this->render('sale/form.html.twig', [
            'form' => $form,
            'previews' => $previews,
            'totals' => $previewer->totals($lines, $previews),
        ], new Response(status: $status));
    }

    /**
     * Lieu et article de la première ligne : `?lieu=` (ou valeur soumise, ou lieu actif unique) ;
     * `?variant=<id>&etat=<value>` présélectionne cet article d'occasion s'il est en stock dans ce lieu
     * (premier état d'occasion disponible sans `etat`). Sans lieu, celui de la première occasion de la variante.
     *
     * @return array{?Location, ?string} lieu, valeur du choix « <variantId>-<état> »
     */
    private function prefill(Request $request, LocationRepository $locations, StockLevelRepository $levels): array
    {
        $location = $this->selectedLocation($request, $locations, 'sale');
        $variantId = $request->query->getString('variant');
        if (!ctype_digit($variantId)) {
            return [$location, null];
        }
        $condition = $request->query->getString('etat');
        $match = static fn (StockLevel $level): bool => (string) $level->getVariant()->getId() === $variantId
            && ('' === $condition || $level->getCondition()->value === $condition);
        if (null === $location) {
            // Sans lieu (à l'affichage seulement) : celui de la première occasion de la variante dans un lieu actif.
            $level = $request->request->has('sale') ? null : array_find($levels->findSecondHand(null), static fn (StockLevel $candidate): bool => $match($candidate) && $candidate->getLocation()->isActive());
            $location = $level?->getLocation();
        } else {
            $level = array_find($levels->findSecondHand($location), $match);
        }

        return [$location, null === $level ? null : SaleLineType::value($level)];
    }
}
