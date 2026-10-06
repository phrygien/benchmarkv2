<?php

namespace App\Jobs;

use App\Services\CompetitorPriceService;
use App\Services\TopVenteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Export Excel des top ventes (+ prix concurrents, une colonne par site).
 *
 *  - $all = false : uniquement la page demandée ($page / $perPage)
 *  - $all = true  : toutes les pages correspondant aux filtres
 *
 * La progression est publiée dans le cache (clé statusKey) pour que l'interface
 * Livewire puisse l'afficher et proposer le téléchargement.
 */
class ExportTopVentesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    private const EUR_CODES = ['EUR', '€'];

    private const STATUS_TTL = 3600;

    /**
     * @param string $token    identifiant d'export (suivi de progression)
     * @param array  $filters  filtres déjà normalisés (TopVenteService::filtersFrom)
     */
    public function __construct(
        public string $token,
        public array $filters,
        public bool $all = false,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }

    public static function statusKey(string $token): string
    {
        return "top-ventes:export:{$token}";
    }

    public function handle(TopVenteService $sales, CompetitorPriceService $competitorPrices): void
    {
        $this->status('running', ['done' => 0, 'total' => null]);

        $country = $this->filters['country'];
        $page    = $this->all ? 1 : max(1, $this->page);
        $size    = $this->all ? TopVenteService::MAX_PER_PAGE : $this->perPage;

        $lines = [];   // lignes intermédiaires
        $sites = [];   // siteKey => nom du site (colonnes dynamiques)

        do {
            $result = $sales->paginate($this->filters, $page, $size);
            $data   = $result['data'];

            $comparisons = $competitorPrices->forEans(array_column($data, 'ean'), $country);

            foreach ($data as $row) {
                $line = $this->buildLine($row, $comparisons[$row['ean']] ?? $competitorPrices->emptyComparison());

                foreach ($line['sites'] as $key => $site) {
                    $sites[$key] = $site['name'];
                }

                $lines[] = $line;
            }

            $this->status('running', [
                'done'  => count($lines),
                'total' => $this->all ? $result['total_item'] : count($data),
            ]);

            $page++;
        } while ($this->all && $page <= $result['total_page']);

        // Colonnes concurrents : ordre alphabétique des noms de site
        asort($sites, SORT_NATURAL | SORT_FLAG_CASE);

        $headings = array_merge([
            'Rang qté',
            'Rang CA',
            'EAN',
            'Groupe',
            'Marque',
            'Désignation',
            'Qté vendue',
            'CA',
            'Notre prix',
            'Coût',
            'PGHT',
            'Meilleur prix concurrent',
            'Meilleur concurrent',
            'Écart moyenne marché (€)',
            'Écart moyenne marché (%)',
        ], array_values($sites));

        $rows = array_map(function (array $line) use ($sites) {
            $prices = [];

            foreach (array_keys($sites) as $key) {
                $prices[] = $line['sites'][$key]['prix_ht'] ?? null;
            }

            return array_merge($line['base'], $prices);
        }, $lines);

        $name = sprintf(
            'top-ventes_%s_%s_%s_%s_%s.xlsx',
            $country,
            $this->filters['date_from'],
            $this->filters['date_to'],
            $this->all ? 'tout' : 'page-' . $this->page,
            now()->format('Ymd-His')
        );
        $path = 'exports/' . $name;

        $this->writeXlsx(Storage::disk('local')->path($path), $headings, $rows);

        $this->status('done', [
            'done'  => count($rows),
            'total' => count($rows),
            'path'  => $path,
            'name'  => $name,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->status('failed', ['message' => $e->getMessage()]);
    }

    /**
     * Une ligne d'export : colonnes fixes + relevé par site (dernier relevé de chaque site).
     */
    private function buildLine(array $row, array $comparison): array
    {
        $ours = (float) ($row['prix_vente_cosma'] ?? 0);

        // Un relevé par site réel (dédoublonné sur l'URL), le plus récent
        $perSite = collect($comparison['competitors'])
            ->groupBy(fn ($c) => self::siteKey($c['website']['url'] ?? null))
            ->map(fn ($group) => $group->sortByDesc('scraped_at')->first());

        // Marché = sites en euros uniquement (comme l'API)
        $eur = $perSite->filter(
            fn ($c) => in_array(strtoupper(trim((string) ($c['currency'] ?? ''))), self::EUR_CODES, true)
        );

        $best = $eur->sortBy('prix_ht')->first();
        $avg  = $eur->isNotEmpty() ? (float) $eur->avg('prix_ht') : null;
        $diff = $avg !== null ? round($avg - $ours, 2) : null;
        $pct  = ($diff !== null && $ours > 0) ? round($diff / $ours * 100, 2) : null;

        return [
            'base' => [
                $row['rank_qty'],
                $row['rank_ca'],
                $row['ean'],
                $row['groupe'],
                $row['marque'],
                $row['designation_produit'],
                $row['total_qty_sold'],
                $row['total_revenue'],
                $row['prix_vente_cosma'],
                $row['cost'],
                $row['pght'],
                $best['prix_ht'] ?? null,
                $best['website']['name'] ?? null,
                $diff,
                $pct,
            ],
            'sites' => $perSite->map(fn ($c) => [
                'name'    => $c['website']['name'] ?? '?',
                'prix_ht' => (float) $c['prix_ht'],
            ])->all(),
        ];
    }

    /**
     * Écriture en flux (OpenSpout) : une ligne à la fois, mémoire quasi constante.
     * Les EAN restent des chaînes (zéros de tête conservés).
     */
    private function writeXlsx(string $absolutePath, array $headings, array $rows): void
    {
        Storage::disk('local')->makeDirectory('exports');

        // Palette alignée sur la page Livewire (zinc / vert / rouge)
        $zinc = '3F3F46';
        $muted = '71717A';
        $green = '16A34A';
        $greenBg = 'DCFCE7';
        $red = 'DC2626';
        $redBg = 'FEE2E2';

        $cache = [];
        $style = function (
            ?string $format = null,
            string $color = '3F3F46',
            bool $bold = false,
            ?string $bg = null,
            CellAlignment $align = CellAlignment::LEFT,
        ) use (&$cache): Style {
            $key = implode('|', [$format, $color, (int) $bold, $bg, $align->value]);

            // OpenSpout 5 : Style est immuable, tout passe par le constructeur
            return $cache[$key] ??= new Style(
                fontBold: $bold,
                fontSize: 10,
                fontColor: $color,
                cellAlignment: $align,
                backgroundColor: $bg,
                format: $format,
            );
        };

        $euro    = '#,##0.00 "€"';
        $euroGap = '+#,##0.00 "€";-#,##0.00 "€";0.00 "€"';
        $pctGap  = '+0.00"%";-0.00"%";0.00"%"';
        $firstSite = 15; // colonnes fixes : A..O, puis une colonne par site

        $writer = new Writer();
        $writer->getOptions()->setColumnWidth(10, 1, 2);
        $writer->getOptions()->setColumnWidth(16, 3);
        $writer->getOptions()->setColumnWidth(22, 4, 5);
        $writer->getOptions()->setColumnWidth(45, 6);
        $writer->getOptions()->setColumnWidth(16, 7, 8, 9, 10, 11, 12, 13, 14, 15);

        $writer->openToFile($absolutePath);

        // En-tête : fond sombre, texte blanc, centré
        $writer->addRow(Row::fromValuesWithStyle($headings, $style(null, 'FFFFFF', true, '27272A', CellAlignment::CENTER)));

        foreach ($rows as $values) {
            $ours = round((float) ($values[8] ?? 0), 2);
            $cells = [];

            foreach ($values as $i => $value) {
                $cellStyle = match (true) {
                    $i <= 1  => $style(null, $zinc, false, null, CellAlignment::CENTER),
                    $i === 2 => $style(null, $muted),
                    $i === 4 => $style(null, $zinc, true),
                    $i === 6 => $style('#,##0', $zinc, true, null, CellAlignment::RIGHT),
                    $i === 8 => $style($euro, $zinc, true, null, CellAlignment::RIGHT),
                    $i === 7, $i === 9, $i === 10 => $style($euro, $i === 7 ? $zinc : $muted, false, null, CellAlignment::RIGHT),
                    $i === 11 => $style($euro, $zinc, true, null, CellAlignment::RIGHT),
                    $i === 12 => $style(null, $muted),

                    // Écarts : vert = concurrents plus chers, rouge = moins chers
                    $i === 13, $i === 14 => match (true) {
                        $value === null || (float) $value === 0.0 => $style($i === 13 ? $euroGap : $pctGap, $zinc, false, null, CellAlignment::CENTER),
                        (float) $value > 0                         => $style($i === 13 ? $euroGap : $pctGap, $green, true, $greenBg, CellAlignment::CENTER),
                        default                                    => $style($i === 13 ? $euroGap : $pctGap, $red, true, $redBg, CellAlignment::CENTER),
                    },

                    // Colonnes concurrents : couleur selon l'écart avec notre prix, gras = meilleur prix retenu
                    $i >= $firstSite => (function () use ($value, $ours, $values, $headings, $i, $style, $euro, $zinc, $green, $red) {
                        if ($value === null) {
                            return $style(null, $zinc, false, null, CellAlignment::CENTER);
                        }

                        $price  = round((float) $value, 2);
                        $color  = $price < $ours ? $red : ($price > $ours ? $green : $zinc);
                        $isBest = $values[12] !== null
                            && $headings[$i] === $values[12]
                            && $price === round((float) $values[11], 2);

                        return $style($euro, $color, $isBest, null, CellAlignment::CENTER);
                    })(),

                    default => $style(null, $zinc),
                };

                // EAN (colonne C) : toujours une chaîne
                if ($i === 2) {
                    $value = (string) $value;
                }

                $cells[] = Cell::fromValue($value, $cellStyle);
            }

            $writer->addRow(new Row($cells));
        }

        $writer->close();
    }

    private static function siteKey(?string $url): string
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    private function status(string $state, array $extra = []): void
    {
        Cache::put(self::statusKey($this->token), ['state' => $state] + $extra, self::STATUS_TTL);
    }
}
