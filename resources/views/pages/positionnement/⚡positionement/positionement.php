<?php

use App\Services\PositionnementPrixService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const COUNTRIES = [
        'FR'  => 'France',
        'DE'  => 'Allemagne',
        'BE'  => 'Belgique',
        'NL'  => 'Pays-Bas',
        'IT'  => 'Italie',
        'ES'  => 'Espagne',
        'all' => 'Tous',
    ];

    private const REFERENCES = [
        'median' => 'Prix médian du marché',
        'avg'    => 'Prix moyen du marché',
        'best'   => 'Meilleur prix du marché',
    ];

    // Au-delà, on arrête d'attendre un recalcul (secondes)
    private const REFRESH_TIMEOUT = 900;

    #[Url]
    public string $country = 'FR';

    // Référence "marché" : median | avg | best
    #[Url]
    public string $reference = 'median';

    // Marque (vendor) ; vide = toutes
    #[Url]
    public string $vendor = '';

    // Ignorer les relevés plus vieux que N jours (0 = tous)
    #[Url]
    public int $maxAge = 0;

    // Écart (en %) en dessous duquel on considère "même prix"
    #[Url]
    public float $tolerance = 0.5;

    // Filtre du tableau produits : all | pricier | cheaper | same
    #[Url]
    public string $position = 'all';

    // Tri du tableau : gap_desc | gap_asc | gap_abs
    #[Url]
    public string $order = 'gap_desc';

    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 25;

    public ?string $error = null;

    // Recalcul demandé par l'utilisateur, en attente de la fin du job
    public bool $refreshing = false;
    public int $refreshStartedAt = 0;
    public int $refreshFromBuiltAt = 0;

    // Tout changement de filtre => retour page 1
    public function updated(string $property): void
    {
        if (! str_contains($property, 'page')) {
            $this->resetPage();
        }
    }

    /**
     * Demande un recalcul en arrière-plan (job). Les chiffres actuels restent affichés
     * jusqu'à ce que le nouveau jeu de données soit prêt (suivi par checkRefresh).
     */
    public function refresh(): void
    {
        $this->error = null;

        try {
            $this->refreshFromBuiltAt = (int) ($this->dataset['built_at'] ?? 0);

            app(PositionnementPrixService::class)->dispatchRefresh();

            $this->refreshing       = true;
            $this->refreshStartedAt = time();
        } catch (\Throwable $e) {
            Log::error('Positionnement prix (Livewire) refresh : ' . $e->getMessage(), ['exception' => $e]);
            $this->error = $e->getMessage();
        }
    }

    /**
     * Appelée par wire:poll tant qu'un calcul est en cours.
     */
    public function checkRefresh(): void
    {
        if (! $this->refreshing) {
            return;
        }

        $built = (int) ($this->dataset['built_at'] ?? 0);

        if ($built > $this->refreshFromBuiltAt) {
            $this->refreshing = false;

            return;
        }

        if (time() - $this->refreshStartedAt > self::REFRESH_TIMEOUT) {
            $this->refreshing = false;
            $this->error = $this->error
                ?: 'Le recalcul prend plus de temps que prévu. Vérifiez que le worker tourne (php artisan queue:work) '
                . 'ou lancez php artisan positionnement:build --force.';
        }
    }

    #[Computed]
    public function countries(): array
    {
        return self::COUNTRIES;
    }

    #[Computed]
    public function references(): array
    {
        return self::REFERENCES;
    }

    /**
     * Jeu de données compact, LU DANS LE CACHE (instantané). Si absent ou périmé,
     * le service déclenche lui-même le calcul en arrière-plan.
     */
    #[Computed]
    public function dataset(): array
    {
        $this->error = null;

        try {
            $service = app(PositionnementPrixService::class);
            $data    = $service->dataset();

            // Dernière erreur du job (ex. API comparateur en échec)
            $this->error = $service->lastError();

            return $data;
        } catch (\Throwable $e) {
            Log::error('Positionnement prix (Livewire) : ' . $e->getMessage(), ['exception' => $e]);
            $this->error = $e->getMessage();

            return ['built_at' => null, 'rows' => []];
        }
    }

    // Premier calcul : aucune donnée en cache pour l'instant
    #[Computed]
    public function isBuilding(): bool
    {
        return ($this->dataset['built_at'] ?? null) === null;
    }

    // Données de plus de 30 min (un recalcul est lancé en arrière-plan par le service)
    #[Computed]
    public function isStale(): bool
    {
        $ts = $this->dataset['built_at'] ?? null;

        return $ts !== null && app(PositionnementPrixService::class)->isStale((int) $ts);
    }

    #[Computed]
    public function builtAt(): ?string
    {
        $ts = $this->dataset['built_at'] ?? null;

        return $ts
            ? Carbon::createFromTimestamp($ts)->setTimezone(config('app.timezone'))->format('d/m/Y H:i')
            : null;
    }

    #[Computed]
    public function vendors(): array
    {
        $vendors = array_unique(array_column($this->dataset['rows'], 'vendor'));
        sort($vendors, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($vendors);
    }

    /**
     * Statistiques et liste des produits, selon les filtres actifs.
     *
     * Pour chaque produit : prix de référence du marché (médiane / moyenne / meilleur prix
     * des concurrents en euros, même contenance, pays et fraîcheur choisis), puis
     *   écart % = notre prix ÷ prix marché − 1
     *   > 0 : nous vendons plus cher que le marché
     *   < 0 : nous vendons moins cher que le marché
     */
    #[Computed]
    public function analysis(): array
    {
        $cut = $this->maxAge > 0 ? now()->subDays($this->maxAge)->getTimestamp() : null;
        $tol = max(0.0, $this->tolerance);

        $newBucket = fn () => ['n' => 0, 'cheaper' => 0, 'same' => 0, 'pricier' => 0, 'sum' => 0.0];

        $add = function (array &$bucket, string $position, float $gapPct): void {
            $bucket['n']++;
            $bucket[$position]++;
            $bucket['sum'] += $gapPct;
        };

        $global   = $newBucket();
        $byVendor = [];
        $byType   = [];
        $bySite   = [];
        $gaps     = [];
        $list     = [];

        foreach ($this->dataset['rows'] as $r) {
            if ($this->vendor !== '' && $r['vendor'] !== $this->vendor) {
                continue;
            }

            // Relevés retenus : pays de l'onglet + fraîcheur
            $valid = array_values(array_filter($r['competitors'], function (array $c) use ($cut) {
                if ($this->country !== 'all' && $c['country'] !== $this->country) {
                    return false;
                }

                return $cut === null || ($c['ts'] !== null && $c['ts'] >= $cut);
            }));

            if (empty($valid)) {
                continue;
            }

            $prices = array_column($valid, 'price');
            sort($prices);

            $count = count($prices);
            $min   = $prices[0];
            $max   = $prices[$count - 1];
            $avg   = array_sum($prices) / $count;
            $mid   = intdiv($count, 2);
            $med   = $count % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;

            $market = match ($this->reference) {
                'best'  => $min,
                'avg'   => $avg,
                default => $med,
            };

            if ($market <= 0) {
                continue;
            }

            $ours   = (float) $r['ours'];
            $gapPct = ($ours / $market - 1) * 100; // > 0 : nous sommes plus chers

            $position = abs($gapPct) <= $tol ? 'same' : ($gapPct > 0 ? 'pricier' : 'cheaper');

            $add($global, $position, $gapPct);

            $byVendor[$r['vendor']] ??= $newBucket();
            $add($byVendor[$r['vendor']], $position, $gapPct);

            $byType[$r['type']] ??= $newBucket();
            $add($byType[$r['type']], $position, $gapPct);

            $gaps[] = $gapPct;

            $list[] = [
                'sku'      => $r['sku'],
                'title'    => $r['title'],
                'vendor'   => $r['vendor'],
                'ours'     => $ours,
                'market'   => round($market, 2),
                'min'      => $min,
                'max'      => $max,
                'n'        => $count,
                'gap_eur'  => round($ours - $market, 2),
                'gap_pct'  => round($gapPct, 1),
                'position' => $position,
            ];

            // Statistiques par concurrent (indépendantes de la référence choisie)
            foreach ($valid as $c) {
                $bySite[$c['key']] ??= [
                    'name'     => $c['name'],
                    'country'  => $c['country'],
                    'compared' => 0,
                    'cheapest' => 0,
                    'sum'      => 0.0,
                ];

                $bySite[$c['key']]['compared']++;
                $bySite[$c['key']]['sum'] += ($ours / $c['price'] - 1) * 100;

                // Concurrent(s) au meilleur prix (ex æquo inclus)
                if ($c['price'] <= $min + 0.005) {
                    $bySite[$c['key']]['cheapest']++;
                }
            }
        }

        // Médiane des écarts
        $median = null;

        if (! empty($gaps)) {
            sort($gaps);
            $mid    = intdiv(count($gaps), 2);
            $median = count($gaps) % 2 ? $gaps[$mid] : ($gaps[$mid - 1] + $gaps[$mid]) / 2;
        }

        $finalize = function (array $buckets): array {
            $rows = [];

            foreach ($buckets as $name => $b) {
                $rows[] = $b + ['name' => (string) $name, 'avg' => $b['n'] ? $b['sum'] / $b['n'] : 0.0];
            }

            usort($rows, fn ($a, $b) => $b['n'] <=> $a['n']);

            return $rows;
        };

        $sites = array_map(
            fn ($s) => $s + ['avg' => $s['compared'] ? $s['sum'] / $s['compared'] : 0.0],
            array_values($bySite)
        );
        usort($sites, fn ($a, $b) => $b['cheapest'] <=> $a['cheapest']);

        return [
            'global'  => $global + ['avg' => $global['n'] ? $global['sum'] / $global['n'] : 0.0, 'median' => $median],
            'vendors' => $finalize($byVendor),
            'types'   => $finalize($byType),
            'sites'   => $sites,
            'list'    => $list,
        ];
    }

    /**
     * Tableau des produits : filtré (position, recherche), trié, paginé.
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $page    = $this->getPage();
        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];

        $rows = $this->analysis['list'];

        if ($this->position !== 'all') {
            $rows = array_filter($rows, fn ($p) => $p['position'] === $this->position);
        }

        $needle = mb_strtolower(trim($this->search));

        if ($needle !== '') {
            $rows = array_filter($rows, fn ($p) => str_contains(mb_strtolower($p['title'] . ' ' . $p['sku']), $needle));
        }

        $rows = array_values($rows);

        usort($rows, match ($this->order) {
            'gap_asc' => fn ($a, $b) => $a['gap_pct'] <=> $b['gap_pct'],
            'gap_abs' => fn ($a, $b) => abs($b['gap_pct']) <=> abs($a['gap_pct']),
            default   => fn ($a, $b) => $b['gap_pct'] <=> $a['gap_pct'],
        });

        return new LengthAwarePaginator(
            items: array_slice($rows, ($page - 1) * $this->perPage, $this->perPage),
            total: count($rows),
            perPage: $this->perPage,
            currentPage: $page,
            options: $options,
        );
    }
};
?>
