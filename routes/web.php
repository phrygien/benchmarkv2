<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.auth.login')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    // Produit boutique
    Route::livewire('/produits/boutiques', 'pages::produit.boutique')->name('produits.boutiques');
    // Comparateur page
    Route::livewire('/magento-product-pays', 'pages::comparateur.produit-par-pays')->name('magento.product-pays');

    // Top vente magento par pays
    Route::livewire('/top/produit-top-vente', 'pages::top.vente-par-pays')->name('top.vente-par-pays');
    Route::livewire('/top/produit-google', 'pages::top.google-product-par-pays')->name('top.google');
});

require __DIR__.'/settings.php';
