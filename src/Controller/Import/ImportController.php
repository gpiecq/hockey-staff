<?php

declare(strict_types=1);

namespace App\Controller\Import;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\ImportRun;
use App\Entity\Season;
use App\Entity\Supplier;
use App\Enum\ImportRunStatus;
use App\Form\ImportUploadType;
use App\Import\CatalogueImportApplier;
use App\Import\Exception\ImportNotApplicableException;
use App\Import\Exception\StaleImportException;
use App\Import\ImportDiff;
use App\Import\Message\AnalyzeImportRun;
use App\Pagination\Page;
use App\Repository\ImportRunRepository;
use App\Repository\SupplierImportMappingRepository;
use App\Repository\VariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Imports de catalogues fournisseurs : dépôt, aperçu du diff, application ou annulation (Manager et plus). */
#[Route('/imports')]
#[IsGranted('ROLE_MANAGER')]
final class ImportController extends AbstractController
{
    /** Lignes affichées au maximum par section de l'aperçu (les anomalies sont toujours toutes affichées). */
    private const int PREVIEW_LIMIT = 30;

    public function __construct(
        #[Autowire('%app.imports_dir%')]
        private readonly string $importsDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'import_index', methods: ['GET'])]
    public function index(Request $request, ImportRunRepository $runs): Response
    {
        $qb = $runs->createQueryBuilder('r')
            ->addSelect('s', 'se')
            ->join('r.supplier', 's')
            ->join('r.season', 'se')
            ->orderBy('r.id', 'DESC');

        return $this->render('import/index.html.twig', [
            'page' => Page::fromQuery($qb, $request->query->getString('page')),
        ]);
    }

    #[Route('/new', name: 'import_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
        SupplierImportMappingRepository $mappings,
        CurrentSeasonProvider $currentSeason,
    ): Response {
        $form = $this->createForm(ImportUploadType::class, ['season' => $currentSeason->get()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            \assert(\is_array($data));
            $supplier = $data['supplier'];
            $season = $data['season'];
            $file = $data['file'];
            \assert($supplier instanceof Supplier && $season instanceof Season && $file instanceof UploadedFile);

            if (null === $mappings->findOneBy(['supplier' => $supplier])) {
                $form->get('supplier')->addError(new FormError('Aucun mapping d\'import pour ce fournisseur.'));
            } else {
                $storedPath = bin2hex(random_bytes(16)).'.xlsx';
                $file->move($this->importsDir, $storedPath);

                $run = new ImportRun($supplier, $season, $file->getClientOriginalName(), $storedPath);
                $em->persist($run);
                $em->flush();
                // Après le flush : le worker doit retrouver le run.
                $bus->dispatch(new AnalyzeImportRun((int) $run->getId()));

                return $this->redirectToRoute('import_show', ['id' => $run->getId()]);
            }
        }

        return $this->render('import/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'import_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ImportRun $run, VariantRepository $variants): Response
    {
        $diff = ImportRunStatus::Analyzed === $run->getStatus() || ImportRunStatus::Applied === $run->getStatus()
            ? ImportDiff::fromArray($run->getDiff() ?? [])
            : null;

        $labels = [];
        if (null !== $diff) {
            $ids = array_values(array_unique([
                ...\array_slice($diff->deactivatedVariantIds, 0, self::PREVIEW_LIMIT),
                ...\array_slice($diff->reactivatedVariantIds, 0, self::PREVIEW_LIMIT),
                ...array_map(static fn (array $c): int => $c['variantId'], \array_slice($diff->changedVariants, 0, self::PREVIEW_LIMIT)),
                ...array_filter(array_map(static fn (array $p): ?int => $p['variantId'], \array_slice($diff->priceChanges, 0, self::PREVIEW_LIMIT))),
            ]));
            if ([] !== $ids) {
                foreach ($variants->findBy(['id' => $ids]) as $variant) {
                    $labels[(int) $variant->getId()] = trim(($variant->getProduct()?->getReference() ?? '').' '.$variant->getLabel());
                }
            }
        }

        return $this->render('import/show.html.twig', [
            'run' => $run,
            'diff' => $diff,
            'summary' => $diff?->summary(),
            'labels' => $labels,
            'limit' => self::PREVIEW_LIMIT,
        ]);
    }

    #[Route('/{id}/apply', name: 'import_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(ImportRun $run, Request $request, CatalogueImportApplier $applier): Response
    {
        $id = (int) $run->getId();
        $this->checkCsrf('import_apply_'.$id, $request);

        try {
            $applied = $applier->apply($run);
        } catch (StaleImportException $e) {
            // L'EntityManager a pu être fermé par la transaction : on ne le touche plus, on redirige seulement.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('import_show', ['id' => $id]);
        } catch (ImportNotApplicableException) {
            $this->addFlash('error', 'Cet import ne peut plus être appliqué : il n\'est plus à l\'état « Analysé ».');

            return $this->redirectToRoute('import_show', ['id' => $id]);
        } catch (\Throwable $e) {
            // Échec de lecture/analyse/écriture : la transaction est annulée (EM peut être fermé), on ne le touche plus.
            $this->logger->error('Application d\'import en échec.', ['importRunId' => $id, 'exception' => $e]);
            $reason = $e instanceof \DomainException ? $e->getMessage() : 'erreur inattendue, consultez les journaux.';
            $this->addFlash('error', 'L\'import n\'a pas pu être appliqué, aucune donnée n\'a été modifiée : '.$reason);

            return $this->redirectToRoute('import_show', ['id' => $id]);
        }

        $touched = [];
        foreach ($applied->changedVariants as $change) {
            $touched[$change['variantId']] = true;
        }
        foreach ($applied->priceChanges as $change) {
            if (null !== $change['variantId']) {
                $touched[$change['variantId']] = true;
            }
        }
        $this->addFlash('success', \sprintf(
            'Import appliqué : %d créations, %d modifications, %d désactivations.',
            \count($applied->newProducts) + \count($applied->newVariants),
            \count($touched),
            \count($applied->deactivatedVariantIds),
        ));

        return $this->redirectToRoute('import_show', ['id' => $id]);
    }

    #[Route('/{id}/cancel', name: 'import_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(ImportRun $run, Request $request, CatalogueImportApplier $applier): Response
    {
        $id = (int) $run->getId();
        $this->checkCsrf('import_cancel_'.$id, $request);

        try {
            $applier->cancel($run);
        } catch (\LogicException) {
            $this->addFlash('error', 'Cet import ne peut plus être annulé : il est déjà appliqué ou annulé.');

            return $this->redirectToRoute('import_show', ['id' => $id]);
        }
        $this->addFlash('success', 'Import annulé.');

        return $this->redirectToRoute('import_show', ['id' => $id]);
    }

    private function checkCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
