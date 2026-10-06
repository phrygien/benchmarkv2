<?php

namespace App\Jobs;

use App\Services\Competitorpriceservice;
use App\Services\GoogleTopProduitService;
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
 * Export Excel des top produits Google (+ prix concurrents, une colonne par site).
 *
 *  - $all = false : uniquement la page demandée ($page / $perPage)
 *  - $all = true  : toutes les pages correspondant aux filtres
 *
 * La progression est publiée dans le cache (clé statusKey) pour que l'interface
 * Livewire puisse l'afficher et proposer le téléchargement.
 */
class ExportGoogleTopProduitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    private const STATUS_TTL = 3600;

    /**
     * @param string $token    identifiant d'export (suivi de progression)
     * @param array  $filters  filtres déjà normalisés (GoogleTopProduitService::filtersFrom)
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
        return "google-top-produits:export:{$token}";
    }

    public function handle(GoogleTopProduitService $google, Competitorpriceservice $competitorPrices): void
    {
        $this->status('running', ['done' => 0, 'total' => null]);

        $country = $this->filters['country'];
        $page    = $this->all ? 1 : max(1, $this->page);
        $size    = $this->all ? GoogleTopProduitService::MAX_PER_PAGE : $this->perPage;

        $lines = [];   // lignes intermédiaires
        $sites = [];   // siteKey => nom du site (colonnes dynamiques)

        do {
            $result = $google->paginate($this->filters, $page, $size);

            // Ajoute competitors + market (sites en euros, écart moyenne − notre prix)
            $data = $google->withCompetitors($result['data'], $country, $competitorPrices);

            foreach ($data as $row) {
                $line = $this->buildLine($row);

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
            'Rang',
            'EAN',
            'Groupe',
            'Marque',
            'Désignation',
            'Clics',
            'Impressions',
            'CTR',
            'Conversions',
            'Valeur conversions',
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
            'top-google_%s_%s_%s_%s_%s.xlsx',
            $country,
            $this->filters['date_from'],
            $this->filters['date_to'],
            $this->all ? 'tout' : 'page-' . $this->page,
            now()->format('Ymd-His')
        );
        $path = 'exports/' . $name;

        // Libère la mémoire avant l'écriture
        unset($lines);

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
    private function buildLine(array $row): array
    {
        $market = $row['market'] ?? [];

        // Un relevé par site réel (dédoublonné sur l'URL), le plus récent
        $perSite = collect($row['competitors'] ?? [])
            ->groupBy(fn ($c) => self::siteKey($c['website']['url'] ?? null))
            ->map(fn ($group) => $group->sortByDesc('scraped_at')->first());

        return [
            'base' => [
                $row['rank'],
                $row['ean'],
                $row['groupe'],
                $row['marque'],
                $row['designation_produit'],
                $row['clicks'],
                $row['impressions'],
                $row['click_through_rate'],
                $row['conversions'],
                $row['conversion_value'],
                $row['prix_vente_cosma'],
                $row['cost'],
                $row['pght'],
                $market['best']['prix_ht'] ?? null,
                $market['best']['website'] ?? null,
                $market['diff'] ?? null,
                $market['diff_pct'] ?? null,
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
     *
     * Colonnes (index 0) : 0 Rang · 1 EAN · 2 Groupe · 3 Marque · 4 Désignation · 5 Clics ·
     * 6 Impressions · 7 CTR · 8 Conversions · 9 Valeur conv. · 10 Notre prix · 11 Coût · 12 PGHT ·
     * 13 Meilleur prix · 14 Meilleur concurrent · 15 Écart € · 16 Écart % · 17+ sites
     */
    private function writeXlsx(string $absolutePath, array $headings, array $rows): void
    {
        Storage::disk('local')->makeDirectory('exports');

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

            return $cache[$key] ??= new Style(
                fontBold: $bold,
                fontSize: 10,
                fontColor: $color,
                cellAlignment: $align,
                backgroundColor: $bg,
                format: $format,
            );
        };

        $euro      = '#,##0.00 "€"';
        $euroGap   = '+#,##0.00 "€";-#,##0.00 "€";0.00 "€"';
        $pctGap    = '+0.00"%";-0.00"%";0.00"%"';
        $firstSite = 17;

        $writer = new Writer();
        $writer->getOptions()->setColumnWidth(8, 1);
        $writer->getOptions()->setColumnWidth(16, 2);
        $writer->getOptions()->setColumnWidth(22, 3, 4);
        $writer->getOptions()->setColumnWidth(45, 5);
        $writer->getOptions()->setColumnWidth(16, ...range(6, count($headings)));

        $writer->openToFile($absolutePath);

        $writer->addRow(Row::fromValuesWithStyle($headings, $style(null, 'FFFFFF', true, '27272A', CellAlignment::CENTER)));

        foreach ($rows as $values) {
            $ours  = round((float) ($values[10] ?? 0), 2);
            $cells = [];

            foreach ($values as $i => $value) {
                $cellStyle = match (true) {
                    $i === 0  => $style(null, $zinc, false, null, CellAlignment::CENTER),
                    $i === 1  => $style(null, $muted),
                    $i === 3  => $style(null, $zinc, true),
                    $i === 5, $i === 6 => $style('#,##0', $zinc, $i === 5, null, CellAlignment::RIGHT),
                    $i === 7  => $style('0.00%', $zinc, false, null, CellAlignment::RIGHT),
                    $i === 8  => $style('#,##0.00', $zinc, false, null, CellAlignment::RIGHT),
                    $i === 9  => $style($euro, $zinc, false, null, CellAlignment::RIGHT),
                    $i === 10 => $style($euro, $zinc, true, null, CellAlignment::RIGHT),
                    $i === 11, $i === 12 => $style($euro, $muted, false, null, CellAlignment::RIGHT),
                    $i === 13 => $style($euro, $zinc, true, null, CellAlignment::RIGHT),
                    $i === 14 => $style(null, $muted),

                    // Écarts : vert = concurrents plus chers, rouge = moins chers
                    $i === 15, $i === 16 => match (true) {
                        $value === null || (float) $value === 0.0 => $style($i === 15 ? $euroGap : $pctGap, $zinc, false, null, CellAlignment::CENTER),
                        (float) $value > 0                         => $style($i === 15 ? $euroGap : $pctGap, $green, true, $greenBg, CellAlignment::CENTER),
                        default                                    => $style($i === 15 ? $euroGap : $pctGap, $red, true, $redBg, CellAlignment::CENTER),
                    },

                    // Colonnes concurrents : couleur selon l'écart avec notre prix, gras = meilleur prix retenu
                    $i >= $firstSite => (function () use ($value, $ours, $values, $headings, $i, $style, $euro, $zinc, $green, $red) {
                        if ($value === null) {
                            return $style(null, $zinc, false, null, CellAlignment::CENTER);
                        }

                        $price  = round((float) $value, 2);
                        $color  = $ours <= 0 ? $zinc : ($price < $ours ? $red : ($price > $ours ? $green : $zinc));
                        $isBest = $values[14] !== null
                            && $headings[$i] === $values[14]
                            && $price === round((float) $values[13], 2);

                        return $style($euro, $color, $isBest, null, CellAlignment::CENTER);
                    })(),

                    default => $style(null, $zinc),
                };

                // EAN : toujours une chaîne
                if ($i === 1) {
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
