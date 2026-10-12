<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\PositionnementPrixService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(PositionnementPrixService::class)->dispatchRefresh())
    ->everyThirtyMinutes()
    ->name('positionnement-refresh');
