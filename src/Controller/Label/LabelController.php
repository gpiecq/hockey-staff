<?php

declare(strict_types=1);

namespace App\Controller\Label;

use App\Entity\Variant;
use App\Label\LabelFormat;
use App\Label\LabelLine;
use App\Label\LabelLineBuilder;
use App\Label\LabelPdfRenderer;
use App\Repository\ProductRepository;
use App\Repository\StockOperationRepository;
use App\Repository\VariantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/etiquettes')]
#[IsGranted('STOCK_MOVE')]
final class LabelController extends AbstractController
{
    private const int MAX_LABELS = 500;
    private const int CELLS = 21;

    public function __construct(
        private readonly LabelLineBuilder $builder,
        private readonly VariantRepository $variants,
        private readonly ProductRepository $products,
        private readonly StockOperationRepository $operations,
    ) {
    }

    #[Route('', name: 'label_print', methods: ['GET'])]
    public function print(Request $request): Response
    {
        $variants = [];
        foreach ($this->ids($request->query->all('variantes')) as $id) {
            $variant = $this->variants->find($id);
            if ($variant instanceof Variant) {
                $variants[$id] = $variant;
            }
        }
        foreach ($this->ids($request->query->all('produits')) as $id) {
            $product = $this->products->find($id);
            foreach (null === $product ? [] : $this->variants->findLiveByProduct($product) as $variant) {
                $variants[(int) $variant->getId()] = $variant;
            }
        }
        $variants = array_values($variants);

        $lines = $this->builder->forVariants($variants);

        $operationId = $request->query->getInt('operation');
        $operation = $operationId > 0 ? $this->operations->find($operationId) : null;
        if (null !== $operation) {
            array_push($lines, ...$this->builder->forOperation($operation));
        }

        if ($request->query->getBoolean('sans_ean')) {
            array_push($lines, ...$this->builder->withoutValidEan());
        }

        return $this->render('label/print.html.twig', [
            'lines' => $lines,
            'carried' => $this->carriedParameters($request),
            'format' => LabelFormat::Sheet,
            'first_cell' => 1,
            'error' => null,
        ]);
    }

    #[Route('/pdf', name: 'label_pdf', methods: ['POST'])]
    public function pdf(Request $request, LabelPdfRenderer $renderer): Response
    {
        if (!$this->isCsrfTokenValid('label_pdf', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $lines = $this->postedLines($request);
        $format = LabelFormat::tryFrom($request->request->getString('format'));
        $firstCell = filter_var($request->request->getString('premiere_case', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::CELLS]]);
        $total = array_sum(array_map(static fn (LabelLine $line): int => $line->copies, $lines));

        $error = match (true) {
            null === $format => 'Format d\'étiquette inconnu.',
            LabelFormat::Sheet === $format && false === $firstCell => \sprintf('La première case doit être comprise entre 1 et %d.', self::CELLS),
            0 === $total => 'Aucune étiquette à imprimer.',
            $total > self::MAX_LABELS => \sprintf('%d étiquettes au maximum par PDF : réduisez la sélection.', self::MAX_LABELS),
            default => null,
        };

        if (null !== $error) {
            return $this->render('label/print.html.twig', [
                'lines' => array_values(array_filter($lines, static fn (LabelLine $line): bool => $line->copies > 0)),
                'carried' => [],
                'format' => $format ?? LabelFormat::Sheet,
                'first_cell' => false === $firstCell ? 1 : $firstCell,
                'error' => $error,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $labels = [];
        foreach ($lines as $line) {
            for ($i = 0; $i < $line->copies; ++$i) {
                $labels[] = $line->toLabel();
            }
        }

        return new Response($renderer->render($labels, $format, false === $firstCell ? 1 : $firstCell), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquettes.pdf"',
        ]);
    }

    /**
     * Lignes postées, rechargées en base (inconnues, supprimées ou non étiquetables ignorées) avec leur nombre d'exemplaires ;
     * les lignes retirées sont écartées.
     *
     * @return list<LabelLine>
     */
    private function postedLines(Request $request): array
    {
        $lines = [];
        foreach ($request->request->all('lignes') as $row) {
            if (!\is_array($row) || '' !== trim(self::string($row['retirer'] ?? ''))) {
                continue;
            }
            $copies = self::copies(self::string($row['exemplaires'] ?? ''));
            $id = trim(self::string($row['id'] ?? ''));

            if ('variante' === ($row['type'] ?? null) && ctype_digit($id)) {
                $variant = $this->variants->find((int) $id);
                $line = $variant instanceof Variant ? ($this->builder->forVariants([$variant])[0] ?? null) : null;
                if (null !== $line) {
                    $lines[] = new LabelLine($line->variant, $copies);
                }
            }
        }

        return $lines;
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** Entier positif ; négatif ou non numérique compte comme 0. */
    private static function copies(string $value): int
    {
        return ctype_digit(trim($value)) ? min((int) trim($value), 100000) : 0;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_map('intval', array_filter($values, static fn (mixed $v): bool => \is_string($v) && ctype_digit($v))));
    }

    /**
     * Paramètres de source à reporter dans le formulaire de choix du mode.
     *
     * @return list<array{string, string}>
     */
    private function carriedParameters(Request $request): array
    {
        $carried = [];
        foreach (['variantes', 'produits'] as $name) {
            foreach ($request->query->all($name) as $value) {
                if (\is_string($value)) {
                    $carried[] = [$name.'[]', $value];
                }
            }
        }
        foreach (['operation', 'sans_ean'] as $name) {
            if ($request->query->has($name) && \is_scalar($request->query->all()[$name])) {
                $carried[] = [$name, (string) $request->query->all()[$name]];
            }
        }

        return $carried;
    }
}
