<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Colonnes fixes (A..Q) :
 *  A Rang · B EAN · C Groupe · D Marque · E Désignation
 *  F Clics · G Impressions · H CTR · I Conversions · J Valeur conv.
 *  K Notre prix · L Coût · M PGHT
 *  N Meilleur prix concurrent · O Meilleur concurrent · P Écart € · Q Écart %
 * puis une colonne par site concurrent (à partir de R).
 */
class GoogleTopProduitsExport extends DefaultValueBinder implements
    FromArray,
    WithHeadings,
    ShouldAutoSize,
    WithStyles,
    WithColumnFormatting,
    WithCustomValueBinder
{
    // Colonne B = EAN (texte, pour garder les zéros de tête)
    private const EAN_COLUMN = 'B';

    // Colonnes montants : valeur conv., prix, coût, PGHT, meilleur prix, écart €
    private const MONEY_COLUMNS = ['J', 'K', 'L', 'M', 'N', 'P'];

    // Première colonne « concurrent » (après les 17 colonnes fixes)
    private const FIRST_SITE_COLUMN = 18;

    public function __construct(
        private array $headings,
        private array $rows,
    ) {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }

    public function columnFormats(): array
    {
        $formats = [
            self::EAN_COLUMN => '@',
            'F' => '#,##0',
            'G' => '#,##0',
            'H' => '0.00%',      // CTR stocké en ratio (0.034 = 3,40 %)
            'I' => '#,##0.00',
            'Q' => '0.00',
        ];

        foreach (self::MONEY_COLUMNS as $col) {
            $formats[$col] = '#,##0.00';
        }

        for ($i = self::FIRST_SITE_COLUMN; $i <= count($this->headings); $i++) {
            $formats[Coordinate::stringFromColumnIndex($i)] = '#,##0.00';
        }

        return $formats;
    }

    // L'EAN doit rester une chaîne (sinon 0088300601400 devient 88300601400)
    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === self::EAN_COLUMN && $cell->getRow() > 1) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
