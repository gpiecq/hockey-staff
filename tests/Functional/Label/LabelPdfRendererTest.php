<?php

declare(strict_types=1);

namespace App\Tests\Functional\Label;

use App\Label\Label;
use App\Label\LabelBarcode;
use App\Label\LabelFormat;
use App\Label\LabelPdfRenderer;
use App\Label\Symbology;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LabelPdfRendererTest extends KernelTestCase
{
    public function testSheetFitsTwentyOneLabelsPerPage(): void
    {
        $renderer = $this->renderer();

        $pdf = $renderer->render($this->labels(21), LabelFormat::Sheet);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(1, $this->pageCount($pdf));

        self::assertSame(2, $this->pageCount($renderer->render($this->labels(22), LabelFormat::Sheet)));
    }

    public function testFirstCellShiftsLabels(): void
    {
        $pdf = $this->renderer()->render($this->labels(20), LabelFormat::Sheet, 3);

        self::assertSame(2, $this->pageCount($pdf));
    }

    public function testRollPrintsOneLabelPerPage(): void
    {
        $pdf = $this->renderer()->render($this->labels(3), LabelFormat::Roll);

        self::assertSame(3, $this->pageCount($pdf));
    }

    public function testLongDesignationDoesNotOverflowCell(): void
    {
        $pdf = $this->renderer()->render($this->labels(21, str_repeat('Gant hockey ', 10)), LabelFormat::Sheet);

        self::assertSame(1, $this->pageCount($pdf));
    }

    public function testRollBarsKeepQuietZones(): void
    {
        // UPC-A / EAN-13: 95 modules ; Code128 HV-000001: 11 * (9 + 2) + 13 = 134 modules, 146 with start/check margin.
        foreach ([[95, 11.0], [146, 10.0]] as [$modules, $quietModules]) {
            $width = LabelPdfRenderer::rollBarsWidthMm($modules);
            $module = $width / $modules;
            self::assertGreaterThanOrEqual($quietModules * $module, (62.0 - $width) / 2, \sprintf('%d modules', $modules));
        }
        // Long Code128 values are capped so the bars never run to the edge.
        self::assertSame(52.0, LabelPdfRenderer::rollBarsWidthMm(400));
    }

    public function testEverySymbologyRenders(): void
    {
        $labels = [
            new Label('UPC', 'Taille 52', new LabelBarcode(Symbology::UpcA, '012345678905')),
            new Label('EAN', '', new LabelBarcode(Symbology::Ean13, '4006381333931')),
            new Label('Interne', 'Noir', new LabelBarcode(Symbology::Code128, 'HV-000001')),
        ];

        foreach ([LabelFormat::Sheet, LabelFormat::Roll] as $format) {
            self::assertStringStartsWith('%PDF', $this->renderer()->render($labels, $format));
        }
    }

    public function testInvalidArgumentsThrow(): void
    {
        $renderer = $this->renderer();

        foreach ([[[], 1], [$this->labels(1), 0], [$this->labels(1), 22]] as [$labels, $firstCell]) {
            try {
                $renderer->render($labels, LabelFormat::Sheet, $firstCell);
                self::fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function renderer(): LabelPdfRenderer
    {
        self::bootKernel();

        $renderer = self::getContainer()->get(LabelPdfRenderer::class);
        self::assertInstanceOf(LabelPdfRenderer::class, $renderer);

        return $renderer;
    }

    /**
     * @return list<Label>
     */
    private function labels(int $count, string $designation = 'Gant de hockey'): array
    {
        $labels = [];
        for ($i = 1; $i <= $count; ++$i) {
            $labels[] = new Label($designation, 'Taille 52', new LabelBarcode(Symbology::Ean13, '4006381333931'));
        }

        return $labels;
    }

    private function pageCount(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }
}
