<?php

namespace App\Console\Commands;

use App\Services\PositionnementPrixService;
use Illuminate\Console\Command;

class BuildPositionnementCommand extends Command
{
    protected $signature   = 'positionnement:build {--force : Libère un verrou resté bloqué avant de construire}';
    protected $description = 'Construit le jeu de données de positionnement prix (produits matchés, mis en cache)';

    public function handle(PositionnementPrixService $service): int
    {
        if ($this->option('force')) {
            $service->forceUnlock();
            $this->comment('Verrou libéré.');
        }

        $start = microtime(true);
        $data  = $service->buildAndStore();

        if ($data === null) {
            $this->warn('Une construction est déjà en cours (relancez avec --force si elle est bloquée).');

            return self::SUCCESS;
        }

        $s = $data['stats'] ?? [];

        $this->info(sprintf('%d produits exploitables en %.1f s.', count($data['rows']), microtime(true) - $start));

        if ($s) {
            $this->table(['Indicateur', 'Valeur'], [
                ['Produits matchés reçus', $s['received']],
                ['Produits exploitables', $s['kept']],
                ['Sans prix chez nous', $s['no_price']],
                ['Sans relevé comparable', $s['no_competitor']],
                ['Relevés reçus', $s['rel_total']],
                ['Relevés écartés : sans prix', $s['rel_price']],
                ['Relevés écartés : doublons', $s['rel_dupes']],
            ]);
        }

        return self::SUCCESS;
    }
}
