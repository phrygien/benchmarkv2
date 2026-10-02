<?php

use App\Http\Controllers\Api\ComparateurController;
use App\Http\Controllers\Api\ProduitboutiqueController;
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
