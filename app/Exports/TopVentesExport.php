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

class TopVentesExport extends DefaultValueBinder implements
    FromArray,
    WithHeadings,
    ShouldAutoSize,
    WithStyles,
    WithColumnFormatting,
    WithCustomValueBinder
{
    // Colonne C = EAN (texte, pour garder les zéros de tête)
    private const EAN_COLUMN = 'C';

    // Colonnes montants : CA, prix, coût, PGHT, meilleur prix, écart €
    private const MONEY_COLUMNS = ['H', 'I', 'J', 'K', 'L', 'N'];

    // Première colonne « concurrent » (après les 15 colonnes fixes)
    private const FIRST_SITE_COLUMN = 16;

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
        $formats = [self::EAN_COLUMN => '@'];

        foreach (self::MONEY_COLUMNS as $col) {
            $formats[$col] = '#,##0.00';
        }

        $formats['O'] = '0.00';

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
