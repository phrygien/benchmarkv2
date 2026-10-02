<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    // Produit boutique
    Route::livewire('/produits/boutiques', 'pages::produit.boutique')->name('produits.boutiques');
    // Comparateur page
    Route::livewire('/magento-product-pays', 'pages::comparateur.produit-par-pays')->name('magento.product-pays');
});

require __DIR__.'/settings.php';
