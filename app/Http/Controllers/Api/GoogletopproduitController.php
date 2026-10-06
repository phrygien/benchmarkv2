<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Competitorpriceservice;
use App\Services\GoogleTopProduitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Top produits Google Shopping (Merchant Center) + prix concurrents.
 *
 *  GET    /api/google-top-produits          liste paginée (sort = clicks | impressions | conversions)
 *  GET    /api/google-top-produits/groupes  marques pour le filtre
 *  DELETE /api/google-top-produits/cache    vide le cache
 */
class GoogletopproduitController extends Controller
{
    public function __construct(
        private GoogleTopProduitService $service,
        private Competitorpriceservice $competitorPrices,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($invalid = $this->validationError($request)) {
            return $invalid;
        }

        $filters = $this->service->filtersFrom($request);
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(
            GoogleTopProduitService::MAX_PER_PAGE,
            max(1, (int) $request->input('per_page', GoogleTopProduitService::DEFAULT_PER_PAGE))
        );

        // Concurrents activés par défaut
        $withCompetitors = $request->has('with_competitors')
            ? $request->boolean('with_competitors')
            : true;

        try {
            $result = $this->service->paginate($filters, $page, $perPage);
            $rows   = $result['data'];

            if ($withCompetitors) {
                $rows = $this->service->withCompetitors(
                    $rows,
                    $request->input('competitors_country', $filters['country']),
                    $this->competitorPrices
                );
            }
        } catch (\Throwable $e) {
            Log::error('Google top produits : ' . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Erreur lors de la récupération des top produits Google.',
                'error'   => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'data'  => $rows,
            'meta'  => [
                'total'        => $result['total_item'],
                'per_page'     => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page'    => $result['total_page'],
                'cached_at'    => $result['cached_at'],
                'filters'      => $filters,
            ],
            'links' => $this->service->paginationLinks($request, $result['current_page'], $result['total_page']),
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }

    public function groupes(Request $request): JsonResponse
    {
        if ($invalid = $this->validationError($request)) {
            return $invalid;
        }

        try {
            $groupes = $this->service->groupes($this->service->filtersFrom($request));
        } catch (\Throwable $e) {
            Log::error('Google top produits (groupes) : ' . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Erreur lors de la récupération des marques Google.',
                'error'   => $e->getMessage(),
            ], 502);
        }

        return response()->json(['data' => $groupes]);
    }

    public function clearCache(): JsonResponse
    {
        $this->service->clearCache();

        return response()->json([
            'message'       => 'Cache vidé.',
            'cache_version' => $this->service->cacheVersion(),
        ]);
    }

    private function validationError(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), GoogleTopProduitService::rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Paramètres invalides.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        return null;
    }
}
