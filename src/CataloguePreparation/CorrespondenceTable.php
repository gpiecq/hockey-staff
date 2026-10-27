<?php

declare(strict_types=1);

namespace App\CataloguePreparation;

/**
 * Table de correspondance (onglet du tarif, section) → chemin de catégorie, marque éventuelle, ou catégories
 * candidates quand le couple est ambigu. Fichier CSV versionné, séparateur « ; ».
 *
 * @phpstan-type Row array{onglet: string, section: string, path: list<string>, brand: ?string, candidates: list<list<string>>}
 */
final class CorrespondenceTable
{
    public const array HEADER = ['onglet', 'section', 'categorie1', 'categorie2', 'categorie3', 'marque', 'candidates'];

    public const string PATH_SEPARATOR = ' › ';
    public const string CANDIDATE_SEPARATOR = ' | ';

    /** @param list<Row> $rows */
    private function __construct(private readonly array $rows)
    {
    }

    public static function fromCsv(string $path): self
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw new InvalidCorrespondenceTableException(\sprintf('Table de correspondance introuvable : %s.', $path));
        }

        try {
            $header = fgetcsv($handle, null, ';', '"', '');
            if (\is_array($header) && isset($header[0])) {
                $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
            }
            if (self::HEADER !== $header) {
                throw new InvalidCorrespondenceTableException(\sprintf('En-tête de la table de correspondance invalide : attendu « %s ».', implode(';', self::HEADER)));
            }

            $rows = [];
            $line = 1;
            while (false !== ($cells = fgetcsv($handle, null, ';', '"', ''))) {
                ++$line;
                if ([null] === $cells) {
                    continue; // ligne vide
                }
                $rows[] = self::parseRow(array_map(static fn (?string $c): string => trim((string) $c), $cells), $line);
            }
        } finally {
            fclose($handle);
        }

        return new self($rows);
    }

    /** @return list<Row> */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Lignes telles qu'écrites dans le fichier CSV (en-tête compris).
     *
     * @return list<list<string>>
     */
    public function toTable(): array
    {
        $table = [self::HEADER];
        foreach ($this->rows as $row) {
            $table[] = [
                $row['onglet'],
                $row['section'],
                $row['path'][0] ?? '',
                $row['path'][1] ?? '',
                $row['path'][2] ?? '',
                $row['brand'] ?? '',
                implode(self::CANDIDATE_SEPARATOR, array_map(static fn (array $c): string => implode(self::PATH_SEPARATOR, $c), $row['candidates'])),
            ];
        }

        return $table;
    }

    /**
     * @param list<string> $cells
     *
     * @return Row
     */
    private static function parseRow(array $cells, int $line): array
    {
        if (\count($cells) !== \count(self::HEADER)) {
            throw new InvalidCorrespondenceTableException(\sprintf('Ligne %d : %d colonnes au lieu de %d.', $line, \count($cells), \count(self::HEADER)));
        }
        [$onglet, $section, $c1, $c2, $c3, $brand, $candidates] = $cells;
        if ('' === $onglet || '' === $section) {
            throw new InvalidCorrespondenceTableException(\sprintf('Ligne %d : onglet et section sont obligatoires.', $line));
        }

        $path = [];
        foreach ([$c1, $c2, $c3] as $level) {
            if ('' === $level) {
                break;
            }
            $path[] = $level;
        }
        if (\count($path) !== \count(array_filter([$c1, $c2, $c3], static fn (string $l): bool => '' !== $l))) {
            throw new InvalidCorrespondenceTableException(\sprintf('Ligne %d : niveau de catégorie vide avant un niveau renseigné.', $line));
        }

        $parsedCandidates = [];
        if ('' !== $candidates) {
            foreach (explode('|', $candidates) as $candidate) {
                $levels = array_map(trim(...), explode('›', $candidate));
                if (\in_array('', $levels, true)) {
                    throw new InvalidCorrespondenceTableException(\sprintf('Ligne %d : catégorie candidate vide.', $line));
                }
                $parsedCandidates[] = $levels;
            }
        }

        if (([] === $path) === ([] === $parsedCandidates)) {
            throw new InvalidCorrespondenceTableException(\sprintf('Ligne %d : renseigner soit une catégorie, soit des candidates.', $line));
        }

        return [
            'onglet' => $onglet,
            'section' => $section,
            'path' => $path,
            'brand' => '' === $brand ? null : $brand,
            'candidates' => $parsedCandidates,
        ];
    }
}
