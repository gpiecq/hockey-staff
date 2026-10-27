<?php

declare(strict_types=1);

namespace App\Label;

use Dompdf\Dompdf;
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Twig\Environment;

final readonly class LabelPdfRenderer
{
    private const int CELLS_PER_PAGE = 21;
    private const int COLUMNS = 3;
    private const float TOP_MARGIN_MM = 15.15;
    private const float LEFT_MARGIN_MM = 7.25;
    private const float STEP_X_MM = 66.0;
    private const float STEP_Y_MM = 38.1;
    /** 62 x 29 mm en points. */
    private const array ROLL_PAPER = [0, 0, 175.75, 82.20];

    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param list<Label> $labels
     */
    public function render(array $labels, LabelFormat $format, int $firstCell = 1): string
    {
        if ([] === $labels) {
            throw new \InvalidArgumentException('At least one label is required.');
        }
        if ($firstCell < 1 || $firstCell > self::CELLS_PER_PAGE) {
            throw new \InvalidArgumentException(\sprintf('First cell must be between 1 and %d.', self::CELLS_PER_PAGE));
        }

        $html = $this->twig->render('label/pdf.html.twig', [
            'format' => $format->value,
            'pages' => LabelFormat::Sheet === $format
                ? $this->sheetPages($labels, $firstCell)
                : $this->rollPages($labels),
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper(LabelFormat::Sheet === $format ? 'A4' : self::ROLL_PAPER, 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param list<Label> $labels
     *
     * @return list<list<array{label: Label, image: string, x: float, y: float}>>
     */
    private function sheetPages(array $labels, int $firstCell): array
    {
        $pages = [];
        foreach ($labels as $index => $label) {
            $slot = $index + $firstCell - 1;
            $cell = $slot % self::CELLS_PER_PAGE;
            $pages[intdiv($slot, self::CELLS_PER_PAGE)][] = [
                'label' => $label,
                'image' => $this->barcodeImage($label->barcode),
                'x' => self::LEFT_MARGIN_MM + ($cell % self::COLUMNS) * self::STEP_X_MM,
                'y' => self::TOP_MARGIN_MM + intdiv($cell, self::COLUMNS) * self::STEP_Y_MM,
            ];
        }
        ksort($pages);

        return array_values($pages);
    }

    /**
     * @param list<Label> $labels
     *
     * @return list<list<array{label: Label, image: string}>>
     */
    private function rollPages(array $labels): array
    {
        return array_map(
            fn (Label $label): array => [['label' => $label, 'image' => $this->barcodeImage($label->barcode)]],
            $labels,
        );
    }

    private function barcodeImage(LabelBarcode $barcode): string
    {
        $type = match ($barcode->symbology) {
            Symbology::UpcA => BarcodeGeneratorPNG::TYPE_UPC_A,
            Symbology::Ean13 => BarcodeGeneratorPNG::TYPE_EAN_13,
            Symbology::Code128 => BarcodeGeneratorPNG::TYPE_CODE_128,
        };

        $png = (new BarcodeGeneratorPNG())->getBarcode($barcode->value, $type, 2, 60);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
