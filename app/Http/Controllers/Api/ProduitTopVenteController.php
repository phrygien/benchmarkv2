<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompetitorPriceService;
use App\Services\TopVenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Routes (voir routes/api.php)
|--------------------------------------------------------------------------
|   GET    /api/top-ventes            top ventes de la boutique (Magento) + prix concurrents
|   GET    /api/top-ventes/groupes    vendors vendus sur la période (pour le filtre)
|   DELETE /api/top-ventes/cache      vide le cache
|
| Paramètres de GET /api/top-ventes :
|   country=FR                 pays de livraison des commandes (défaut FR)
|   date_from=2026-01-01       début de période (défaut : 1er janvier de l'année)
|   date_to=2026-12-31         fin de période   (défaut : 31 décembre de l'année)
|   sort=qty|ca                tri : quantité vendue (défaut) ou chiffre d'affaires
|   groupe[]=Lancôme           filtre vendor(s), répétable
|   page, per_page             pagination (per_page max 200, défaut 25)
|   with_competitors=0         n'appelle pas la base des concurrents (plus rapide)
|   competitors_country=FR     pays des sites concurrents ; défaut = country ; "all" = tous
|
| Exemples :
|   GET /api/top-ventes?country=FR&sort=ca&per_page=50
|   GET /api/top-ventes?country=BE&groupe[]=Dior&groupe[]=Chanel&date_from=2026-06-01
*/

class ProduitTopVenteController extends Controller
{
    public function __construct(
        private TopVenteService $sales,
        private CompetitorPriceService $competitors,
    ) {
    }

    /**
     * GET /api/top-ventes
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(TopVenteService::rules());

        $filters = $this->sales->filtersFrom($request);
        $page    = (int) ($request->input('page') ?: 1);
        $perPage = (int) ($request->input('per_page') ?: TopVenteService::DEFAULT_PER_PAGE);

        try {
            $payload = $this->sales->paginate($filters, $page, $perPage);

            $withCompetitors = $request->has('with_competitors')
                ? $request->boolean('with_competitors')
                : true;

            $comparisons = [];

            if ($withCompetitors) {
                $eans = collect($payload['data'])->pluck('ean')->filter()->unique()->values()->all();

                // Par défaut, on compare avec les sites du même pays que les ventes
                $competitorsCountry = strtoupper((string) ($request->input('competitors_country') ?: $filters['country']));
                $competitorsCountry = $competitorsCountry === 'ALL' ? null : $competitorsCountry;

                $comparisons = $this->competitors->forEans($eans, $competitorsCountry);
            }

            $data = array_map(function (array $row) use ($comparisons, $withCompetitors) {
                if (!$withCompetitors) {
                    return $row;
                }

                $comparison = $comparisons[$row['ean']] ?? $this->competitors->emptyComparison();

                return $row + [
                        'competitors'         => $comparison['competitors'],
                        'competitors_summary' => $comparison['summary'],
                        'market'              => $this->market($comparison['competitors'], (float) $row['prix_vente_cosma']),
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
                    'filters'      => $filters,
                ],
                'links' => $this->sales->paginationLinks($request, $payload['current_page'], $payload['total_page']),
            ], 200, [], TopVenteService::JSON_FLAGS);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * GET /api/top-ventes/groupes
     */
    public function groupes(Request $request): JsonResponse
    {
        $request->validate(TopVenteService::rules());

        try {
            $filters = $this->sales->filtersFrom($request);

            return response()->json([
                'data' => $this->sales->groupes($filters),
            ], 200, [], TopVenteService::JSON_FLAGS);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * DELETE /api/top-ventes/cache
     */
    public function clearCache(): JsonResponse
    {
        $this->sales->clearCache();

        return response()->json([
            'message'       => 'Cache des top ventes vidé.',
            'cache_version' => $this->sales->cacheVersion(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Position de notre prix face au marché (concurrents en euros uniquement).
     *   diff     = prix moyen marché - notre prix  (> 0 : nous sommes moins chers)
     *   diff_pct = diff / notre prix * 100
     */
    private function market(array $competitors, float $ourPrice): array
    {
        $eur = array_values(array_filter(
            $competitors,
            fn ($c) => in_array(strtoupper(trim((string) ($c['currency'] ?? ''))), ['EUR', '€'], true)
        ));

        if (empty($eur)) {
            return ['count' => 0, 'min' => null, 'max' => null, 'avg' => null, 'diff' => null, 'diff_pct' => null, 'best' => null];
        }

        $prices = array_map(fn ($c) => (float) $c['prix_ht'], $eur);
        $avg    = array_sum($prices) / count($prices);

        return [
            'count'    => count($prices),
            'min'      => round(min($prices), 2),
            'max'      => round(max($prices), 2),
            'avg'      => round($avg, 2),
            'diff'     => $ourPrice > 0 ? round($avg - $ourPrice, 2) : null,
            'diff_pct' => $ourPrice > 0 ? round(($avg - $ourPrice) / $ourPrice * 100, 2) : null,
            // concurrents déjà triés du moins cher au plus cher
            'best'     => [
                'website' => $eur[0]['website']['name'] ?? null,
                'prix_ht' => $eur[0]['prix_ht'],
                'url'     => $eur[0]['url'] ?? null,
            ],
        ];
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        Log::error('API top-ventes error: ' . $e->getMessage(), ['exception' => $e]);

        return response()->json([
            'message' => 'Erreur lors de la récupération des top ventes.',
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
