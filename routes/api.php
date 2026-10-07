<?php

use App\Http\Controllers\Api\ComparateurController;
use App\Http\Controllers\Api\ProduitboutiqueController;
use App\Http\Controllers\Api\ProduitTopVenteController;
use App\Http\Controllers\Api\GoogletopproduitController;
use App\Http\Controllers\Api\AnalyticController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Produits
|--------------------------------------------------------------------------
*/
Route::prefix('products')->group(function () {
    Route::get('/', [ProduitboutiqueController::class, 'index']);
    Route::get('/cache/stats', [ProduitboutiqueController::class, 'cacheStats']);
    Route::delete('/cache', [ProduitboutiqueController::class, 'clearCache']);
    Route::get('/{id}', [ProduitboutiqueController::class, 'show'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Comparateur (prix concurrents par site)
|--------------------------------------------------------------------------
*/
Route::prefix('comparateur')->group(function () {
    Route::get('/', [ComparateurController::class, 'index']);      // tout le catalogue + prix concurrents
    Route::get('/{sku}', [ComparateurController::class, 'show']);  // un seul SKU / EAN
});

/*
|--------------------------------------------------------------------------
| Top ventes de la boutique (Magento) + prix concurrents
|--------------------------------------------------------------------------
*/
Route::prefix('top-ventes')->group(function () {
    Route::get('/', [ProduitTopVenteController::class, 'index']);          // top ventes + concurrents
    Route::get('/groupes', [ProduitTopVenteController::class, 'groupes']); // vendors pour le filtre
    Route::delete('/cache', [ProduitTopVenteController::class, 'clearCache']);
});

Route::prefix('google-top-produits')->group(function () {
    Route::get('/', [GoogletopproduitController::class, 'index']);
    Route::get('/groupes', [GoogletopproduitController::class, 'groupes']);
    Route::delete('/cache', [GoogletopproduitController::class, 'clearCache']);
});

Route::prefix('analytics')->group(function () {
    Route::get('/summary', [AnalyticController::class, 'summary']);    // KPIs + distributions + tops
    Route::get('/products', [AnalyticController::class, 'products']);  // détail filtrable
    Route::delete('/cache', [AnalyticController::class, 'clearCache']);
});
