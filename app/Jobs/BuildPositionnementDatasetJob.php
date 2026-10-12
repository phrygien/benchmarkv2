<?php

namespace App\Jobs;

use App\Services\PositionnementPrixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BuildPositionnementDatasetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 h : le worker ne tue pas le calcul
    public int $tries   = 1;

    public function handle(PositionnementPrixService $service): void
    {
        if ($service->buildAndStore() === null) {
            Log::warning('Positionnement : construction ignorée, verrou déjà pris (php artisan positionnement:build --force).');
        }
    }

    public function failed(\Throwable $e): void
    {
        // Libère le flag pour permettre une nouvelle tentative
        Cache::forget(PositionnementPrixService::QUEUED_KEY);
        app(PositionnementPrixService::class)->storeError($e->getMessage());
        report($e);
    }
}
