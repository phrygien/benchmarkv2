<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapedProduct;
use App\Services\BoutiqueProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Routes (voir routes/api.php)
|--------------------------------------------------------------------------
|   GET /api/comparateur            tous les produits de la boutique + prix concurrents par site
|   GET /api/comparateur/{sku}      un seul produit (SKU / EAN)
|
| Paramètres de GET /api/comparateur : mêmes filtres que /api/products
|   (search, name, marque, type, ean, min_price, max_price, in_stock, page, per_page)
|   + only_matched=1  => uniquement les produits qui ont au moins un prix concurrent
|
| Exemples :
|   GET /api/comparateur?page=1&per_page=50
|   GET /api/comparateur?only_matched=1&marque=dior
|   GET /api/comparateur/8411061077160
*/

class ComparateurController extends Controller
{
    private const MATCHED_SKUS_TTL = 600; // 10 minutes

    public function __construct(private BoutiqueProductService $products)
    {
    }

    /**
     * GET /api/comparateur
     *
     * Parcourt le catalogue de la boutique (paginé) et ajoute, pour chaque produit,
     * le prix de chaque concurrent trouvé par SKU = EAN.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(
            BoutiqueProductService::rules() + ['only_matched' => ['nullable', 'boolean']]
        );

        $filters = $this->products->filtersFrom($request);
        $page    = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? BoutiqueProductService::DEFAULT_PER_PAGE);

        try {
            // Option : ne garder que les produits présents chez au moins un concurrent
            if ($request->boolean('only_matched')) {
                $filters['skus'] = $this->matchedSkus();
            }

            $payload = $this->products->paginate($filters, $page, $perPage);

            // Une seule requête concurrents pour toute la page
            $skus = collect($payload['data'])->pluck('sku')->filter()->unique()->values()->all();
            $comparisons = $this->competitorsFor($skus);

            $data = array_map(function (array $product) use ($comparisons) {
                $comparison = $comparisons[$product['sku']] ?? $this->emptyComparison();

                return $product + [
                        'competitors'         => $comparison['competitors'],
                        'competitors_summary' => $comparison['summary'],
                    ];
            }, $payload['data']);

            return response()->json([
                'data' => $data,
                'meta' => [
                    'total'        => $payload['total_item'],
                    'per_page'     => $payload['per_page'],
                    'current_page' => $payload['current_page'],
                    'last_page'    => $payload['total_page'],
                    'cached_at'    => $payload['cached_at'],
                ],
                'links' => $this->products->paginationLinks($request, $payload['current_page'], $payload['total_page']),
            ], 200, [], BoutiqueProductService::JSON_FLAGS);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * GET /api/comparateur/{sku}
     */
    public function show(string $sku): JsonResponse
    {
        $sku = trim($sku);

        if ($sku === '') {
            return response()->json(['message' => 'SKU invalide.'], 422);
        }

        try {
            $comparison = $this->competitorsFor([$sku])[$sku] ?? $this->emptyComparison();

            return response()->json([
                'data' => ['sku' => $sku, 'found' => !empty($comparison['competitors'])] + $comparison,
            ], 200, [], BoutiqueProductService::JSON_FLAGS);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Logique de comparaison
    |--------------------------------------------------------------------------
    */

    /**
     * Prix concurrents pour une liste de SKU, en une seule requête.
     * (Appelé avec les SKU d'une seule page : au plus per_page × 5 variantes.)
     *
     * @param  string[]  $skus
     * @return array<string, array{competitors: array, summary: array}>  indexé par SKU
     */
    private function competitorsFor(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $variants = collect($skus)
            ->flatMap(fn ($sku) => $this->eanVariants((string) $sku))
            ->unique()
            ->values()
            ->all();

        $rowsByEan = ScrapedProduct::query()
            ->with('website:id,name,url,country_code')
            ->whereIn('ean', $variants)
            ->whereNotNull('prix_ht')
            ->where('prix_ht', '>', 0)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ScrapedProduct $row) => $this->normalizeEan($row->ean));

        $result = [];

        foreach ($skus as $sku) {
            $sku = (string) $sku;

            // Dernier relevé par site (lignes triées du plus récent au plus ancien),
            // puis tri du moins cher au plus cher
            $latestPerSite = $rowsByEan
                ->get($this->normalizeEan($sku), collect())
                ->unique('web_site_id')
                ->sortBy('prix_ht')
                ->values();

            $result[$sku] = [
                'competitors' => $latestPerSite->map(fn (ScrapedProduct $row) => [
                    'website' => [
                        'id'           => $row->website?->id,
                        'name'         => $row->website?->name,
                        'url'          => $row->website?->url,
                        'country_code' => $row->website?->country_code,
                    ],
                    'prix_ht'    => $row->prix_ht,
                    'currency'   => $row->currency,
                    'name'       => $row->name,
                    'vendor'     => $row->vendor,
                    'type'       => $row->type,
                    'variation'  => $row->variation,
                    'url'        => $row->url,
                    'image_url'  => $row->image_url,
                    'scraped_at' => ($row->updated_at ?? $row->created_at)?->toIso8601String(),
                ])->all(),
                // Statistiques par devise (on ne mélange pas EUR, USD, etc.)
                'summary' => [
                    'count'       => $latestPerSite->count(),
                    'by_currency' => $latestPerSite
                        ->groupBy(fn (ScrapedProduct $row) => $row->currency ?: 'N/A')
                        ->map(fn ($group) => [
                            'count' => $group->count(),
                            'min'   => round($group->min('prix_ht'), 2),
                            'max'   => round($group->max('prix_ht'), 2),
                            'avg'   => round($group->avg('prix_ht'), 2),
                        ])
                        ->all(),
                ],
            ];
        }

        return $result;
    }

    private function emptyComparison(): array
    {
        return [
            'competitors' => [],
            'summary'     => ['count' => 0, 'by_currency' => []],
        ];
    }

    /**
     * Tous les EAN présents chez au moins un concurrent (mis en cache),
     * utilisés pour filtrer le catalogue boutique avec only_matched=1.
     *
     * Les EAN sont renvoyés NORMALISÉS (sans zéros de tête) et sans variantes :
     * BoutiqueProductService::buildWhere() compare avec TRIM(LEADING '0' FROM sku),
     * ce qui couvre UPC-12, EAN-13 et GTIN-14 sans multiplier la taille de la liste.
     *
     * @return string[]
     */
    private function matchedSkus(): array
    {
        return Cache::remember(
            'comparateur:matched-skus:v2:' . $this->products->cacheVersion(),
            self::MATCHED_SKUS_TTL,
            function () {
                return ScrapedProduct::query()
                    ->whereNotNull('ean')
                    ->where('ean', '!=', '')
                    ->where('prix_ht', '>', 0)
                    ->distinct()
                    ->pluck('ean')
                    ->map(fn ($ean) => trim((string) $ean))
                    ->filter(fn ($ean) => $ean !== '' && ctype_digit($ean))
                    ->map(fn ($ean) => ltrim($ean, '0') ?: '0')
                    ->unique()
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * Variantes d'un EAN/SKU à chercher en base (zéros de tête : UPC-12, EAN-13, GTIN-14).
     *
     * @return string[]
     */
    private function eanVariants(string $sku): array
    {
        if (!ctype_digit($sku)) {
            return [$sku];
        }

        $trimmed = ltrim($sku, '0') ?: '0';

        return array_values(array_unique([
            $sku,
            $trimmed,
            str_pad($trimmed, 12, '0', STR_PAD_LEFT),
            str_pad($trimmed, 13, '0', STR_PAD_LEFT),
            str_pad($trimmed, 14, '0', STR_PAD_LEFT),
        ]));
    }

    /**
     * Clé de comparaison : enlève les zéros de tête pour les codes numériques.
     */
    private function normalizeEan(?string $ean): string
    {
        $ean = trim((string) $ean);

        return ctype_digit($ean) ? (ltrim($ean, '0') ?: '0') : $ean;
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        Log::error('API comparateur error: ' . $e->getMessage(), ['exception' => $e]);

        return response()->json([
            'message' => 'Erreur lors de la récupération des prix concurrents.',
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
