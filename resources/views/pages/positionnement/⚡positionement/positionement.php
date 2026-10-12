<?php

use App\Services\PositionnementPrixService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const COUNTRIES = [
        'FR'  => 'France',
        'DE'  => 'Allemagne',
        'BE'  => 'Belgique',
        'NL'  => 'Pays-Bas',
        'IT'  => 'Italie',
        'ES'  => 'Espagne',
        'all' => 'Tous',
    ];

    private const REFERENCES = [
        'median' => 'Prix médian du marché',
        'avg'    => 'Prix moyen du marché',
        'best'   => 'Meilleur prix du marché',
    ];

    private const REFRESH_TIMEOUT = 900;

    // Relevé aberrant : hors de [médiane × 0,4 ; médiane × 2,5] des relevés du produit
    private const OUTLIER_LOW  = 0.4;
    private const OUTLIER_HIGH = 2.5;

    #[Url]
    public string $country = 'FR';

    // Référence "marché" : median | avg | best
    #[Url]
    public string $reference = 'median';

    #[Url]
    public string $vendor = '';

    // Ignorer les relevés plus vieux que N jours (0 = tous)
    #[Url]
    public int $maxAge = 0;

    // Écart (en %) en dessous duquel on considère "même prix"
    #[Url]
    public float $tolerance = 0.5;

    // Notre prix : effective (promo si elle existe) | regular (catalogue)
    #[Url]
    public string $ourPrice = 'effective';

    // Nombre minimum de concurrents pour retenir un produit
    #[Url]
    public int $minSites = 1;

    // Écarter les relevés aberrants (produit à ≥ 3 concurrents)
    #[Url]
    public bool $cleanOutliers = true;

    // Au-delà de ± N % d'écart, le produit est "à vérifier" (0 = désactivé)
    #[Url]
    public int $outlierPct = 50;

    // Exclure les produits "à vérifier" des statistiques
    #[Url]
    public bool $excludeSuspects = true;

    // Filtre du tableau : all | pricier | cheaper | same | suspect
    #[Url]
    public string $position = 'all';

    // Tri : gap_desc | gap_asc | gap_abs | best_desc
    #[Url]
    public string $order = 'gap_desc';

    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 25;

    public ?string $error = null;

    public bool $refreshing = false;
    public int $refreshStartedAt = 0;
    public int $refreshFromBuiltAt = 0;

    public function updated(string $property): void
    {
        if (! str_contains($property, 'page')) {
            $this->resetPage();
        }
    }

    public function refresh(): void
    {
        $this->error = null;

        try {
            $this->refreshFromBuiltAt = (int) ($this->dataset['built_at'] ?? 0);

            // force = true : un clic explicite relance même si un ancien flag est resté bloqué
            app(PositionnementPrixService::class)->dispatchRefresh(true);

            $this->refreshing       = true;
            $this->refreshStartedAt = time();
        } catch (\Throwable $e) {
            Log::error('Positionnement prix (Livewire) refresh : ' . $e->getMessage(), ['exception' => $e]);
            $this->error = $e->getMessage();
        }
    }

    public function checkRefresh(): void
    {
        if (! $this->refreshing) {
            return;
        }

        $built = (int) ($this->dataset['built_at'] ?? 0);

        if ($built > $this->refreshFromBuiltAt) {
            $this->refreshing = false;

            return;
        }

        if (time() - $this->refreshStartedAt > self::REFRESH_TIMEOUT) {
            $this->refreshing = false;
            $this->error = $this->error
                ?: 'Le recalcul prend plus de temps que prévu. Vérifiez que le worker tourne (php artisan queue:work) '
                . 'ou lancez php artisan positionnement:build --force.';
        }
    }

    /**
     * Export CSV (séparateur « ; », UTF-8 avec BOM pour Excel) de la liste filtrée.
     */
    public function export()
    {
        $rows = $this->filtered;

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'SKU', 'EAN', 'Produit', 'Marque', 'Notre prix', 'Prix marché', 'Écart €', 'Écart %',
                'Position', 'Meilleur concurrent', 'Meilleur prix', 'Écart vs meilleur %',
                'Nb concurrents', 'Prix max', 'Promo', 'À vérifier',
            ], ';');

            $n = fn ($v) => number_format((float) $v, 2, ',', '');

            foreach ($rows as $p) {
                fputcsv($out, [
                    $p['sku'], $p['ean'], $p['title'], $p['vendor'], $n($p['ours']), $n($p['market']),
                    $n($p['gap_eur']), $n($p['gap_pct']),
                    ['pricier' => 'Plus cher', 'cheaper' => 'Moins cher', 'same' => 'Même prix'][$p['position']],
                    $p['best_site'], $n($p['min']), $n($p['gap_best_pct']),
                    $p['n'], $n($p['max']), $p['promo'] ? 'oui' : 'non', $p['suspect'] ? 'oui' : 'non',
                ], ';');
            }

            fclose($out);
        }, 'positionnement-prix-' . now()->format('Ymd-Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[Computed]
    public function countries(): array
    {
        return self::COUNTRIES;
    }

    #[Computed]
    public function references(): array
    {
        return self::REFERENCES;
    }

    #[Computed]
    public function dataset(): array
    {
        $this->error = null;

        try {
            $service = app(PositionnementPrixService::class);
            $data    = $service->dataset();

            $this->error = $service->lastError();

            return $data;
        } catch (\Throwable $e) {
            Log::error('Positionnement prix (Livewire) : ' . $e->getMessage(), ['exception' => $e]);
            $this->error = $e->getMessage();

            return ['built_at' => null, 'rows' => [], 'stats' => []];
        }
    }

    #[Computed]
    public function isBuilding(): bool
    {
        return ($this->dataset['built_at'] ?? null) === null;
    }

    #[Computed]
    public function isStale(): bool
    {
        $ts = $this->dataset['built_at'] ?? null;

        return $ts !== null && app(PositionnementPrixService::class)->isStale((int) $ts);
    }

    #[Computed]
    public function builtAt(): ?string
    {
        $ts = $this->dataset['built_at'] ?? null;

        return $ts
            ? Carbon::createFromTimestamp($ts)->setTimezone(config('app.timezone'))->format('d/m/Y H:i')
            : null;
    }

    #[Computed]
    public function vendors(): array
    {
        $vendors = array_unique(array_column($this->dataset['rows'], 'vendor'));
        sort($vendors, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($vendors);
    }

    private function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Statistiques sur les produits matchés, selon les filtres actifs.
     *
     *   écart % = notre prix ÷ prix marché − 1   (> 0 : nous sommes plus chers)
     *
     * Fiabilisation :
     *  - relevés filtrés par pays, fraîcheur, et (option) contenance vérifiée
     *  - relevés aberrants écartés (option)
     *  - produits aux écarts extrêmes marqués "à vérifier" et exclus des stats (option)
     */
    #[Computed]
    public function analysis(): array
    {
        $cut   = $this->maxAge > 0 ? now()->subDays($this->maxAge)->getTimestamp() : null;
        $tol   = max(0.0, $this->tolerance);
        $limit = max(0, $this->outlierPct);
        $min_n = max(1, $this->minSites);

        $newBucket = fn () => ['n' => 0, 'cheaper' => 0, 'same' => 0, 'pricier' => 0, 'sum' => 0.0, 'gaps' => []];

        $add = function (array &$bucket, string $position, float $gapPct): void {
            $bucket['n']++;
            $bucket[$position]++;
            $bucket['sum'] += $gapPct;
            $bucket['gaps'][] = $gapPct;
        };

        $global   = $newBucket();
        $byVendor = [];
        $byType   = [];
        $bySite   = [];
        $gaps     = [];
        $list     = [];
        $weBest   = 0; // produits où nous sommes (ex æquo) le moins cher

        $stats = ['removed' => 0, 'suspects' => 0, 'too_few' => 0];

        foreach ($this->dataset['rows'] as $r) {
            if ($this->vendor !== '' && $r['vendor'] !== $this->vendor) {
                continue;
            }

            $valid = array_values(array_filter($r['competitors'], function (array $c) use ($cut) {
                if ($this->country !== 'all' && $c['country'] !== $this->country) {
                    return false;
                }

                return $cut === null || ($c['ts'] !== null && $c['ts'] >= $cut);
            }));

            if (empty($valid)) {
                continue;
            }

            usort($valid, fn ($a, $b) => $a['price'] <=> $b['price']);

            // Relevés aberrants (ex. mauvais EAN, lot vs unité) : écartés si ≥ 3 concurrents
            if ($this->cleanOutliers && count($valid) >= 3) {
                $m     = $this->median(array_column($valid, 'price'));
                $clean = array_values(array_filter(
                    $valid,
                    fn ($c) => $c['price'] >= $m * self::OUTLIER_LOW && $c['price'] <= $m * self::OUTLIER_HIGH
                ));

                $stats['removed'] += count($valid) - count($clean);
                $valid = $clean;
            }

            if (count($valid) < $min_n) {
                $stats['too_few']++;
                continue;
            }

            $prices = array_column($valid, 'price'); // déjà trié croissant
            $count  = count($prices);
            $min    = $prices[0];
            $max    = $prices[$count - 1];
            $avg    = array_sum($prices) / $count;
            $med    = $this->median($prices);
            $best   = $valid[0];

            $market = match ($this->reference) {
                'best'  => $min,
                'avg'   => $avg,
                default => $med,
            };

            if ($market <= 0) {
                continue;
            }

            $ours    = (float) ($this->ourPrice === 'regular' ? ($r['regular'] ?? $r['ours']) : $r['ours']);
            $gapPct  = ($ours / $market - 1) * 100;
            $gapBest = ($ours / $min - 1) * 100;

            $position = abs($gapPct) <= $tol ? 'same' : ($gapPct > 0 ? 'pricier' : 'cheaper');
            $suspect  = $limit > 0 && abs($gapPct) > $limit;
            $isBest   = $ours <= $min + 0.005;

            if ($suspect) {
                $stats['suspects']++;
            }

            $list[] = [
                'sku'          => $r['sku'],
                'ean'          => $r['ean'] ?? '',
                'title'        => $r['title'],
                'vendor'       => $r['vendor'],
                'ours'         => $ours,
                'market'       => round($market, 2),
                'min'          => $min,
                'max'          => $max,
                'best_site'    => $best['name'],
                'n'            => $count,
                'gap_eur'      => round($ours - $market, 2),
                'gap_pct'      => round($gapPct, 1),
                'gap_best_pct' => round($gapBest, 1),
                'position'     => $position,
                'suspect'      => $suspect,
                'promo'        => (bool) ($r['on_promo'] ?? false),
            ];

            if ($suspect && $this->excludeSuspects) {
                continue;
            }

            $add($global, $position, $gapPct);

            $byVendor[$r['vendor']] ??= $newBucket();
            $add($byVendor[$r['vendor']], $position, $gapPct);

            $byType[$r['type']] ??= $newBucket();
            $add($byType[$r['type']], $position, $gapPct);

            $gaps[] = $gapPct;

            if ($isBest) {
                $weBest++;
            }

            // Statistiques par concurrent
            foreach ($valid as $c) {
                $bySite[$c['key']] ??= [
                    'name'          => $c['name'],
                    'country'       => $c['country'],
                    'compared'      => 0,
                    'cheapest'      => 0,
                    'cheaper_us'    => 0,
                    'gaps'          => [],
                ];

                $bySite[$c['key']]['compared']++;
                $bySite[$c['key']]['gaps'][] = ($ours / $c['price'] - 1) * 100;

                if ($c['price'] <= $min + 0.005) {
                    $bySite[$c['key']]['cheapest']++;
                }

                if ($c['price'] < $ours - 0.005) {
                    $bySite[$c['key']]['cheaper_us']++;
                }
            }
        }

        // Répartition des écarts (histogramme)
        $bins = [
            ['< −20 %',      -INF, -20, 'green'],
            ['−20 à −10 %',  -20,  -10, 'green'],
            ['−10 à −5 %',   -10,  -5,  'green'],
            ['−5 à −1 %',    -5,   -1,  'green'],
            ['−1 à +1 %',    -1,   1,   'zinc'],
            ['+1 à +5 %',    1,    5,   'red'],
            ['+5 à +10 %',   5,    10,  'red'],
            ['+10 à +20 %',  10,   20,  'red'],
            ['> +20 %',      20,   INF, 'red'],
        ];

        $histogram = array_map(function (array $b) use ($gaps) {
            return [
                'label' => $b[0],
                'tone'  => $b[3],
                'n'     => count(array_filter($gaps, fn ($g) => $g >= $b[1] && $g < $b[2])),
            ];
        }, $bins);

        $finalize = function (array $buckets): array {
            $rows = [];

            foreach ($buckets as $name => $b) {
                $rows[] = $b + [
                        'name'   => (string) $name,
                        'avg'    => $b['n'] ? $b['sum'] / $b['n'] : 0.0,
                        'median' => $this->median($b['gaps']) ?? 0.0,
                    ];
            }

            usort($rows, fn ($a, $b) => $b['n'] <=> $a['n']);

            return $rows;
        };

        $sites = array_map(
            fn ($s) => $s + ['median' => $this->median($s['gaps']) ?? 0.0],
            array_values($bySite)
        );
        usort($sites, fn ($a, $b) => $b['cheapest'] <=> $a['cheapest']);

        return [
            'global'    => $global + [
                    'avg'    => $global['n'] ? $global['sum'] / $global['n'] : 0.0,
                    'median' => $this->median($gaps),
                    'best'   => $weBest,
                ],
            'vendors'   => $finalize($byVendor),
            'types'     => $finalize($byType),
            'sites'     => $sites,
            'list'      => $list,
            'histogram' => $histogram,
            'stats'     => $stats,
        ];
    }

    /**
     * Liste filtrée (position, recherche) et triée. Sert au tableau ET à l'export CSV.
     */
    #[Computed]
    public function filtered(): array
    {
        $rows = array_filter($this->analysis['list'], function (array $p) {
            if ($this->position === 'suspect') {
                return $p['suspect'];
            }

            // Les produits "à vérifier" n'apparaissent que dans leur filtre dédié quand ils sont exclus
            if ($this->excludeSuspects && $p['suspect']) {
                return false;
            }

            return $this->position === 'all' || $p['position'] === $this->position;
        });

        $needle = mb_strtolower(trim($this->search));

        if ($needle !== '') {
            $rows = array_filter($rows, fn ($p) => str_contains(mb_strtolower($p['title'] . ' ' . $p['sku'] . ' ' . $p['ean']), $needle));
        }

        $rows = array_values($rows);

        usort($rows, match ($this->order) {
            'gap_asc'   => fn ($a, $b) => $a['gap_pct'] <=> $b['gap_pct'],
            'gap_abs'   => fn ($a, $b) => abs($b['gap_pct']) <=> abs($a['gap_pct']),
            'best_desc' => fn ($a, $b) => $b['gap_best_pct'] <=> $a['gap_best_pct'],
            default     => fn ($a, $b) => $b['gap_pct'] <=> $a['gap_pct'],
        });

        return $rows;
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $page    = $this->getPage();
        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];
        $rows    = $this->filtered;

        return new LengthAwarePaginator(
            items: array_slice($rows, ($page - 1) * $this->perPage, $this->perPage),
            total: count($rows),
            perPage: $this->perPage,
            currentPage: $page,
            options: $options,
        );
    }
};
?>
