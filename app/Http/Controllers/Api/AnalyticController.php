<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BoutiqueProductService;
use App\Services\PriceAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnalyticController extends Controller
{
    public function __construct(
        private PriceAnalyticsService $analytics,
        private BoutiqueProductService $boutique,
    ) {
    }

    /** KPIs, distributions, par vendor / concurrent, tops. */
    public function summary(Request $request): JsonResponse
    {
        $report = $this->report($request);

        return response()->json([
            'meta'    => $report['meta'],
            'summary' => $report['summary'],
        ]);
    }

    /** Détail produit par produit, filtrable et paginé. */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => 'nullable|in:lowest,competitive,above_market,overpriced,no_data',
            'vendor'   => 'nullable|string',
            'search'   => 'nullable|string',
            'sort'     => 'nullable|in:gap_vs_median_pct,gap_vs_min_pct,price_index,price_ht',
            'dir'      => 'nullable|in:asc,desc',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $rows = collect($this->report($request)['products'])
            ->when($request->status, fn ($c, $s) => $c->where('status', $s))
            ->when($request->boolean('opportunity'), fn ($c) => $c->where('opportunity', true))
            ->when($request->vendor, fn ($c, $v) => $c->filter(fn ($r) => strcasecmp((string) $r['vendor'], $v) === 0))
            ->when($request->search, fn ($c, $q) => $c->filter(
                fn ($r) => str_contains(mb_strtolower(($r['name'] ?? '') . ' ' . $r['ean']), mb_strtolower($q))
            ));

        if ($sort = $request->input('sort')) {
            $rows = $request->input('dir', 'desc') === 'asc'
                ? $rows->sortBy($sort) : $rows->sortByDesc($sort);
        }

        $perPage = (int) $request->input('per_page', 50);
        $page    = (int) $request->input('page', 1);

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'total'        => $rows->count(),
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($rows->count() / $perPage)),
            ],
        ]);
    }

    public function clearCache(): JsonResponse
    {
        Cache::forget('analytics:keys');
        foreach (Cache::get('analytics:keys', []) as $key) {
            Cache::forget($key);
        }

        return response()->json(['message' => 'Cache analytique vidé']);
    }

    // ------------------------------------------------------------------

    private function report(Request $request): array
    {
        $request->validate([
            'country'  => 'nullable|string|size:2',
            'currency' => 'nullable|string|size:3',
        ]);

        $country  = $request->input('country');
        $currency = strtoupper($request->input('currency', 'EUR'));
        $key      = 'analytics:report:' . md5(($country ?? 'all') . '|' . $currency);

        // On mémorise les clés pour pouvoir vider le cache
        $keys = Cache::get('analytics:keys', []);
        Cache::forever('analytics:keys', array_values(array_unique([...$keys, $key])));

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->analytics->analyze(
            $this->shopProducts(),
            $country,
            $currency
        ));
    }

    /**
     * Seul point à adapter : renvoie la liste des produits de la boutique
     * sous forme de tableaux avec au minimum sku (ou ean), name, vendor, price_ht.
     */
    private function shopProducts(): array
    {
        $products = $this->boutique->all(); // adapte au nom réel de la méthode

        return collect($products)->map(fn ($p) => (array) $p)->all();
    }
}
