<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Boutiqueproductservice;
use App\Services\PriceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnalyticController extends Controller
{
    private const CACHE_TTL_MINUTES = 10;
    private const KEYS_CACHE        = 'analytics:keys';

    public function __construct(
        private PriceAnalyticsService $analytics,
        private Boutiqueproductservice $boutique,
    ) {
    }

    /**
     * KPIs, distributions, par marque / concurrent, tops.
     * GET /api/analytics/summary?country=FR&currency=EUR
     */
    public function summary(Request $request): JsonResponse
    {
        $report = $this->report($request);

        return response()->json([
            'meta'    => $report['meta'],
            'summary' => $report['summary'],
        ]);
    }

    /**
     * Détail produit par produit, filtrable, triable et paginé.
     * GET /api/analytics/products?status=overpriced&sort=gap_vs_median_pct&page=1
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'status'      => 'nullable|in:lowest,competitive,above_market,overpriced,no_data',
            'vendor'      => 'nullable|string',
            'search'      => 'nullable|string',
            'opportunity' => 'nullable|boolean',
            'sort'        => 'nullable|in:gap_vs_median_pct,gap_vs_min_pct,price_index,price_ht',
            'dir'         => 'nullable|in:asc,desc',
            'page'        => 'nullable|integer|min:1',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        $rows = collect($this->report($request)['products'])
            ->when($request->filled('status'), fn ($c) => $c->where('status', $request->input('status')))
            ->when($request->boolean('opportunity'), fn ($c) => $c->where('opportunity', true))
            ->when($request->filled('vendor'), fn ($c) => $c->filter(
                fn ($r) => strcasecmp((string) $r['vendor'], (string) $request->input('vendor')) === 0
            ))
            ->when($request->filled('search'), function ($c) use ($request) {
                $q = mb_strtolower((string) $request->input('search'));

                return $c->filter(
                    fn ($r) => str_contains(mb_strtolower(($r['name'] ?? '') . ' ' . $r['ean']), $q)
                );
            });

        if ($request->filled('sort')) {
            $sort = $request->input('sort');

            // Les produits sans données (valeur null) vont toujours en fin de liste
            $rows = $request->input('dir', 'desc') === 'asc'
                ? $rows->sortBy(fn ($r) => $r[$sort] ?? PHP_INT_MAX)
                : $rows->sortByDesc(fn ($r) => $r[$sort] ?? PHP_INT_MIN);
        }

        $perPage = (int) $request->input('per_page', 50);
        $page    = (int) $request->input('page', 1);
        $total   = $rows->count();

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Vide le cache de l'analyse (pas celui du catalogue boutique).
     * DELETE /api/analytics/cache
     */
    public function clearCache(): JsonResponse
    {
        foreach (Cache::get(self::KEYS_CACHE, []) as $key) {
            Cache::forget($key);
        }
        Cache::forget(self::KEYS_CACHE);

        return response()->json(['message' => 'Cache analytique vidé']);
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    private function report(Request $request): array
    {
        $request->validate([
            'country'  => 'nullable|string|size:2',
            'currency' => 'nullable|string|size:3',
        ]);

        $country  = $request->filled('country') ? strtoupper($request->input('country')) : null;
        $currency = strtoupper($request->input('currency', 'EUR'));
        $key      = 'analytics:report:' . md5(($country ?? 'all') . '|' . $currency);

        // On mémorise les clés pour pouvoir vider le cache
        $keys = Cache::get(self::KEYS_CACHE, []);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::forever(self::KEYS_CACHE, $keys);
        }

        return Cache::remember(
            $key,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($country, $currency) {
                // Le 1er calcul parcourt tout le catalogue : on évite le timeout PHP
                @set_time_limit(300);

                return $this->analytics->analyze($this->shopProducts(), $country, $currency);
            }
        );
    }

    /**
     * Tout le catalogue boutique (Magento), page par page.
     * Chaque page passe par le cache du service (1 h) : seul le 1er appel est lent.
     *
     * @return array<int, array{sku:string, ean:string, name:?string, vendor:?string, price_ht:float}>
     */
    private function shopProducts(): array
    {
        $filters = [
            'search'    => '',
            'name'      => '',
            'marque'    => '',
            'type'      => '',
            'ean'       => '',
            'min_price' => null,
            'max_price' => null,
            'in_stock'  => false,
            'skus'      => null,
        ];

        $products = [];
        $page     = 1;

        do {
            $result = $this->boutique->paginate($filters, $page, Boutiqueproductservice::MAX_PER_PAGE);

            foreach ($result['data'] as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));

                if ($sku === '') {
                    continue;
                }

                // Clé = SKU : évite les doublons si un produit revient sur plusieurs pages
                $products[$sku] = [
                    'sku'      => $sku,
                    'ean'      => $sku, // SKU = EAN dans la base Magento
                    'name'     => $row['title'] ?? $row['parent_title'] ?? null,
                    'vendor'   => trim((string) ($row['vendor'] ?? '')) ?: null,
                    'price_ht' => (float) ($row['price'] ?? 0),
                ];
            }

            $page++;
        } while ($page <= (int) ($result['total_page'] ?? 0));

        return array_values($products);
    }
}
