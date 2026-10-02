<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BoutiqueProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Routes (voir routes/api.php)
|--------------------------------------------------------------------------
|   GET    /api/products              liste + filtres + pagination
|   GET    /api/products/{id}         détail d'un produit
|   GET    /api/products/cache/stats  infos sur le cache
|   DELETE /api/products/cache        vide le cache produits
|
| Exemples :
|   GET /api/products?search=chanel&per_page=20&page=2
|   GET /api/products?marque=dior&type=parfum&min_price=10&in_stock=1
*/

class ProduitboutiqueController extends Controller
{
    public function __construct(private BoutiqueProductService $products)
    {
    }

    /**
     * GET /api/products
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(BoutiqueProductService::rules());

        $filters = $this->products->filtersFrom($request);
        $page    = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? BoutiqueProductService::DEFAULT_PER_PAGE);

        try {
            $payload = $this->products->paginate($filters, $page, $perPage);

            return response()->json([
                'data' => $payload['data'],
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
            return $this->errorResponse($e, 'Erreur lors de la récupération des produits.');
        }
    }

    /**
     * GET /api/products/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $product = $this->products->find($id);

            if (!$product) {
                return response()->json(['message' => 'Produit introuvable.'], 404);
            }

            return response()->json(['data' => $product], 200, [], BoutiqueProductService::JSON_FLAGS);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 'Erreur lors de la récupération du produit.');
        }
    }

    /**
     * DELETE /api/products/cache
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->products->clearCache();

            return response()->json(['message' => 'Cache des produits vidé.']);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 'Erreur lors du vidage du cache.');
        }
    }

    /**
     * GET /api/products/cache/stats
     */
    public function cacheStats(): JsonResponse
    {
        return response()->json([
            'cache_driver'  => config('cache.default'),
            'cache_version' => $this->products->cacheVersion(),
            'ttl_seconds'   => BoutiqueProductService::CACHE_TTL,
        ]);
    }

    private function errorResponse(\Throwable $e, string $message): JsonResponse
    {
        Log::error('API products error: ' . $e->getMessage(), ['exception' => $e]);

        return response()->json([
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
