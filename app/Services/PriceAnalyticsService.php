<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Analyse de positionnement prix : produits de la boutique vs offres concurrentes scrapées.
 */
class PriceAnalyticsService
{
    private const OUTLIER_LOW  = 0.4;   // < 40 % de la médiane => aberrant
    private const OUTLIER_HIGH = 2.5;   // > 250 % de la médiane => aberrant
    private const OVERPRICED   = 1.10;  // > médiane +10 % => trop cher
    private const OPPORTUNITY  = 0.95;  // > 5 % sous le moins cher => marge à récupérer
    private const STALE_DAYS   = 7;

    public function __construct(private Competitorpriceservice $competitors)
    {
    }

    /**
     * @param  array<int, array{ean?:string, sku?:string, name?:string, vendor?:string, price_ht:float}>  $shopProducts
     */
    public function analyze(array $shopProducts, ?string $country = null, string $currency = 'EUR'): array
    {
        $currency = strtoupper($currency);

        $items = collect($shopProducts)
            ->map(fn ($p) => $this->normalizeShop((array) $p))
            ->filter(fn ($p) => $p['ean'] !== '' && $p['price'] > 0)
            ->values();

        // Chunk pour éviter des whereIn gigantesques (5 variantes d'EAN par produit)
        $comparisons = [];
        foreach ($items->pluck('ean')->chunk(500) as $chunk) {
            foreach ($this->competitors->forEans($chunk->all(), $country) as $ean => $cmp) {
                $comparisons[(string) $ean] = $cmp;
            }
        }

        $rows = $items->map(fn ($p) => $this->analyzeProduct(
            $p,
            $comparisons[$p['ean']] ?? $this->competitors->emptyComparison(),
            $currency
        ));

        return [
            'meta' => [
                'country'  => $country ? strtoupper($country) : null,
                'currency' => $currency,
                'products' => $rows->count(),
            ],
            'summary'  => $this->summarize($rows),
            'products' => $rows->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Analyse d'un produit
    // ------------------------------------------------------------------

    private function analyzeProduct(array $p, array $cmp, string $currency): array
    {
        $all = collect($cmp['competitors']);

        $sameCurrency = $all->filter(
            fn ($c) => strtoupper($c['currency'] ?? $currency) === $currency
        )->values();

        $median = $this->median($sameCurrency->pluck('prix_ht')->all());

        $offers = $sameCurrency->map(function ($c) use ($median, $p) {
            $outlier = $median > 0
                && count($this->prices($c)) > 0
                && ($c['prix_ht'] < $median * self::OUTLIER_LOW || $c['prix_ht'] > $median * self::OUTLIER_HIGH);

            return [
                'website_id'   => $c['website']['id'],
                'website'      => $c['website']['name'],
                'country_code' => $c['website']['country_code'],
                'prix_ht'      => $c['prix_ht'],
                'url'          => $c['url'],
                'scraped_at'   => $c['scraped_at'],
                'outlier'      => $outlier,
                // > 0 : le concurrent est plus cher que nous ; < 0 : moins cher
                'gap_vs_us_pct' => round(($c['prix_ht'] - $p['price']) / $p['price'] * 100, 1),
            ];
        });

        // Avec moins de 3 offres, on ne peut pas parler d'aberrant
        if ($offers->count() < 3) {
            $offers = $offers->map(fn ($o) => [...$o, 'outlier' => false]);
        }

        $valid  = $offers->where('outlier', false)->pluck('prix_ht')->values()->all();
        $base   = [
            'ean'            => $p['ean'],
            'sku'            => $p['sku'],
            'name'           => $p['name'],
            'vendor'         => $p['vendor'],
            'price_ht'       => round($p['price'], 2),
            'competitors'    => $offers->all(),
            'ignored_offers' => $all->count() - $sameCurrency->count(), // autre devise
        ];

        if (empty($valid)) {
            return $base + [
                    'status' => 'no_data', 'count' => 0, 'min' => null, 'max' => null, 'avg' => null,
                    'median' => null, 'gap_vs_min_pct' => null, 'gap_vs_median_pct' => null,
                    'price_index' => null, 'rank' => null, 'suggested_price' => null,
                    'stale' => false, 'opportunity' => false,
                ];
        }

        $min    = min($valid);
        $max    = max($valid);
        $avg    = array_sum($valid) / count($valid);
        $med    = $this->median($valid);
        $price  = $p['price'];
        $rank   = 1 + count(array_filter($valid, fn ($v) => $v < $price));

        $status = match (true) {
            $price <= $min                     => 'lowest',
            $price <= $med                     => 'competitive',
            $price <= $med * self::OVERPRICED  => 'above_market',
            default                            => 'overpriced',
        };

        $opportunity = $price < $min * self::OPPORTUNITY;

        $suggested = match (true) {
            $opportunity            => round($min * 0.99, 2),   // juste sous le moins cher
            $status === 'overpriced' => round($med, 2),         // retour à la médiane
            default                 => null,
        };

        $oldest = $offers->where('outlier', false)->pluck('scraped_at')->filter()->min();

        return $base + [
                'status'            => $status,
                'count'             => count($valid),
                'min'               => round($min, 2),
                'max'               => round($max, 2),
                'avg'               => round($avg, 2),
                'median'            => round($med, 2),
                'gap_vs_min_pct'    => round(($price - $min) / $min * 100, 1),
                'gap_vs_median_pct' => round(($price - $med) / $med * 100, 1),
                'price_index'       => round($price / $med * 100, 1),
                'rank'              => $rank,                        // 1 = le moins cher sur count + 1 vendeurs
                'rank_of'           => count($valid) + 1,
                'suggested_price'   => $suggested,
                'opportunity'       => $opportunity,
                'stale'             => $oldest && now()->diffInDays($oldest, true) > self::STALE_DAYS,
            ];
    }

    // ------------------------------------------------------------------
    // Synthèse globale
    // ------------------------------------------------------------------

    private function summarize(Collection $rows): array
    {
        $withData = $rows->where('status', '!=', 'no_data');
        $total    = $rows->count();

        $statuses = ['lowest', 'competitive', 'above_market', 'overpriced', 'no_data'];
        $distribution = [];
        foreach ($statuses as $s) {
            $n = $rows->where('status', $s)->count();
            $distribution[$s] = ['count' => $n, 'pct' => $total ? round($n / $total * 100, 1) : 0];
        }

        return [
            'kpis' => [
                'total_products'     => $total,
                'with_market_data'   => $withData->count(),
                'coverage_pct'       => $total ? round($withData->count() / $total * 100, 1) : 0,
                'avg_price_index'    => round((float) $withData->avg('price_index'), 1),
                'median_price_index' => round($this->median($withData->pluck('price_index')->all()), 1),
                'cheapest_pct'       => $withData->count()
                    ? round($withData->where('status', 'lowest')->count() / $withData->count() * 100, 1) : 0,
                'opportunities'      => $rows->where('opportunity', true)->count(),
                'stale_products'     => $rows->where('stale', true)->count(),
            ],
            'status_distribution' => $distribution,
            'gap_histogram'       => $this->histogram($withData->pluck('gap_vs_median_pct')->all()),
            'by_vendor'           => $this->byVendor($withData),
            'by_competitor'       => $this->byCompetitor($withData),
            'top_overpriced'      => $withData->where('status', 'overpriced')
                ->sortByDesc('gap_vs_median_pct')->take(10)->map(fn ($r) => $this->slim($r))->values()->all(),
            'top_opportunities'   => $withData->where('opportunity', true)
                ->sortBy('gap_vs_min_pct')->take(10)->map(fn ($r) => $this->slim($r))->values()->all(),
        ];
    }

    private function byVendor(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r['vendor'] ?: 'N/A')
            ->map(fn ($g, $vendor) => [
                'vendor'          => $vendor,
                'products'        => $g->count(),
                'avg_price_index' => round((float) $g->avg('price_index'), 1),
                'overpriced_pct'  => round($g->where('status', 'overpriced')->count() / $g->count() * 100, 1),
                'cheapest_pct'    => round($g->where('status', 'lowest')->count() / $g->count() * 100, 1),
            ])
            ->sortByDesc('avg_price_index')->values()->all();
    }

    private function byCompetitor(Collection $rows): array
    {
        $acc = [];

        foreach ($rows as $r) {
            foreach ($r['competitors'] as $o) {
                if ($o['outlier'] || $o['website_id'] === null) {
                    continue;
                }
                $acc[$o['website_id']] ??= [
                    'website_id' => $o['website_id'], 'website' => $o['website'],
                    'country_code' => $o['country_code'], 'common' => 0, 'cheaper_than_us' => 0, 'gaps' => [],
                ];
                $acc[$o['website_id']]['common']++;
                $acc[$o['website_id']]['gaps'][] = $o['gap_vs_us_pct'];
                if ($o['gap_vs_us_pct'] < 0) {
                    $acc[$o['website_id']]['cheaper_than_us']++;
                }
            }
        }

        return collect($acc)->map(fn ($c) => [
            'website_id'          => $c['website_id'],
            'website'             => $c['website'],
            'country_code'        => $c['country_code'],
            'common_products'     => $c['common'],
            'cheaper_than_us'     => $c['cheaper_than_us'],
            'cheaper_than_us_pct' => round($c['cheaper_than_us'] / $c['common'] * 100, 1),
            // < 0 : en moyenne moins cher que nous
            'avg_gap_vs_us_pct'   => round(array_sum($c['gaps']) / count($c['gaps']), 1),
        ])->sortByDesc('cheaper_than_us_pct')->values()->all();
    }

    private function histogram(array $gaps): array
    {
        $buckets = [
            '< -10 %'    => fn ($g) => $g < -10,
            '-10 à -5 %' => fn ($g) => $g >= -10 && $g < -5,
            '-5 à 0 %'   => fn ($g) => $g >= -5 && $g < 0,
            '0 à 5 %'    => fn ($g) => $g >= 0 && $g < 5,
            '5 à 10 %'   => fn ($g) => $g >= 5 && $g < 10,
            '> 10 %'     => fn ($g) => $g >= 10,
        ];

        $out = [];
        foreach ($buckets as $label => $test) {
            $out[] = ['range' => $label, 'count' => count(array_filter($gaps, $test))];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function slim(array $r): array
    {
        return collect($r)->only([
            'ean', 'sku', 'name', 'vendor', 'price_ht', 'min', 'median',
            'gap_vs_min_pct', 'gap_vs_median_pct', 'price_index', 'suggested_price',
        ])->all();
    }

    private function normalizeShop(array $p): array
    {
        $sku = trim((string) ($p['sku'] ?? ''));

        return [
            'ean'    => trim((string) ($p['ean'] ?? $sku)),   // SKU = EAN dans ta base Magento
            'sku'    => $sku,
            'name'   => $p['name'] ?? null,
            'vendor' => $p['vendor'] ?? $p['brand'] ?? $p['groupe'] ?? null,
            'price'  => (float) ($p['price_ht'] ?? $p['price'] ?? $p['prix_ht'] ?? 0),
        ];
    }

    private function prices(array $c): array
    {
        return isset($c['prix_ht']) ? [$c['prix_ht']] : [];
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
