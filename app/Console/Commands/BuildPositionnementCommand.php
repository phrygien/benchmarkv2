<?php

namespace App\Console\Commands;

use App\Services\PositionnementPrixService;
use Illuminate\Console\Command;

class BuildPositionnementCommand extends Command
{
    protected $signature   = 'positionnement:build';
    protected $description = 'Construit le jeu de données de positionnement prix (mis en cache)';

    public function handle(PositionnementPrixService $service): int
    {
        $start = microtime(true);
        $data  = $service->buildAndStore();

        if ($data === null) {
            $this->warn('Une construction est déjà en cours.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d produits en %.1f s.', count($data['rows']), microtime(true) - $start));

        return self::SUCCESS;
    }
}
