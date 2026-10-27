<?php

declare(strict_types=1);

namespace App\Controller\Import;

use App\CataloguePreparation\ImportTemplateBuilder;
use App\Entity\Supplier;
use App\Entity\SupplierImportMapping;
use App\Repository\SupplierImportMappingRepository;
use App\Repository\SupplierRepository;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Préparation d'un import par une IA externe : mode d'emploi, template, règles et table de correspondance. */
#[Route('/imports/preparer')]
#[IsGranted('ROLE_MANAGER')]
final class PreparationController extends AbstractController
{
    private const string RULES_FILE = 'regles-ia.md';

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/catalogue-preparation')]
        private readonly string $resourcesDir,
    ) {
    }

    #[Route('', name: 'import_prepare', methods: ['GET'])]
    public function index(SupplierRepository $suppliers): Response
    {
        // Fournisseurs ayant un mapping d'import : seuls ceux-là ont un template.
        $withMapping = $suppliers->createQueryBuilder('s')
            ->join(SupplierImportMapping::class, 'm', 'WITH', 'm.supplier = s')
            ->orderBy('s.name')
            ->getQuery()
            ->getResult();

        return $this->render('import/prepare.html.twig', ['suppliers' => $withMapping]);
    }

    #[Route('/{supplier}/template.xlsx', name: 'import_prepare_template', requirements: ['supplier' => '\d+'], methods: ['GET'])]
    public function template(
        #[MapEntity(id: 'supplier')] Supplier $supplier,
        SupplierImportMappingRepository $mappings,
        ImportTemplateBuilder $builder,
    ): Response {
        $mapping = $mappings->findOneBy(['supplier' => $supplier]);
        if (!$mapping instanceof SupplierImportMapping) {
            throw $this->createNotFoundException('Aucun mapping d\'import pour ce fournisseur.');
        }

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        if (false === $path) {
            throw new \RuntimeException('Impossible de créer le fichier temporaire du template.');
        }
        (new Xlsx($builder->build($mapping)))->save($path);

        $filename = \sprintf('modele-import-%s.xlsx', (new AsciiSlugger())->slug($supplier->getName())->lower());
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend();

        return $response;
    }

    #[Route('/regles.md', name: 'import_prepare_rules', methods: ['GET'])]
    public function rules(): Response
    {
        $response = new BinaryFileResponse($this->resourcesDir.'/'.self::RULES_FILE);
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, self::RULES_FILE);

        return $response;
    }

    #[Route('/correspondances.csv', name: 'import_prepare_correspondences', methods: ['GET'])]
    public function correspondences(): Response
    {
        $response = new BinaryFileResponse($this->resourcesDir.'/'.ImportTemplateBuilder::CORRESPONDENCES_FILE);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, ImportTemplateBuilder::CORRESPONDENCES_FILE);

        return $response;
    }
}
