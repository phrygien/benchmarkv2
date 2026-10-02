<?php

use App\Http\Controllers\Api\ComparateurController;
use App\Models\ScrapedProduct;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Devises considérées comme des euros (le scraping renvoie "€" ou "EUR")
    private const EUR_CODES = ['EUR', '€'];

    private const COUNTRY_LABELS = [
        'FR'    => 'France',
        'DE'    => 'Allemagne',
        'BE'    => 'Belgique',
        'NL'    => 'Pays-Bas',
        'IT'    => 'Italie',
        'ES'    => 'Espagne',
        'OTHER' => 'Autres',
    ];

    // Pays déduit de l'extension du domaine quand country_code est NULL
    private const TLD_COUNTRIES = [
        'fr' => 'FR', 'de' => 'DE', 'be' => 'BE', 'nl' => 'NL', 'it' => 'IT',
        'es' => 'ES', 'pt' => 'PT', 'ch' => 'CH', 'at' => 'AT', 'lu' => 'LU', 'pl' => 'PL',
    ];

    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 30;

    // Onglet pays : "all" ou un code pays (FR, DE, BE...) ou "OTHER" (France par défaut)
    #[Url]
    public string $country = 'FR';

    // false par défaut : on affiche aussi les produits sans prix concurrent
    #[Url]
    public bool $onlyMatched = false;

    public ?string $error = null;

    // false tant que la page n'a pas fini son premier affichage
    public bool $loaded = false;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyMatched(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $page = $this->getPage();
        $this->error = null;

        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];

        // Premier rendu : page vide instantanée, les données sont chargées ensuite via wire:init
        if (! $this->loaded) {
            return new LengthAwarePaginator([], 0, $this->perPage, $page, $options);
        }

        $query = array_filter([
            'search'       => $this->search,
            'page'         => $page,
            'per_page'     => $this->perPage,
            'only_matched' => $this->onlyMatched ? 1 : 0,
        ], fn ($value) => $value !== '' && $value !== null);

        $response = [];

        try {
            // Appel direct du contrôleur : pas de requête HTTP vers soi-même
            // (évite problèmes de session, d'auth, de certificat et de workers PHP)
            $apiRequest = Request::create(url('/api/comparateur'), 'GET', $query);

            $jsonResponse = app(ComparateurController::class)->index($apiRequest);
            $response     = $jsonResponse->getData(true);

            if ($jsonResponse->getStatusCode() >= 400) {
                throw new \RuntimeException(
                    ($response['message'] ?? 'Erreur API')
                    . (! empty($response['error']) ? ' : ' . $response['error'] : '')
                );
            }

            if (! is_array($response) || ! isset($response['data'], $response['meta'])) {
                throw new \RuntimeException('Réponse inattendue de l\'API (clés "data" / "meta" manquantes).');
            }
        } catch (\Throwable $e) {
            Log::error('Comparateur (Livewire) : ' . $e->getMessage(), ['exception' => $e]);

            $this->error = $e->getMessage();
            $response = [];
        }

        return new LengthAwarePaginator(
            items: collect($response['data'] ?? [])->map(fn (array $row) => $this->enrich($row)),
            total: $response['meta']['total'] ?? 0,
            perPage: $this->perPage,
            currentPage: $response['meta']['current_page'] ?? $page,
            options: $options,
        );
    }

    /**
     * Sites concurrents (une entrée par site réel, les doublons de la table
     * sont fusionnés sur l'URL normalisée). Mis en cache 10 min.
     * Retourne des tableaux (pas de modèles) pour rester compatible avec le cache.
     */
    #[Computed]
    public function websites(): array
    {
        return Cache::remember('comparateur:websites:v2', 600, function () {
            // Modèle lié à la relation ScrapedProduct::website() (pas de nom de classe en dur)
            $related = (new ScrapedProduct)->website()->getRelated();

            return $related->newQuery()
                ->whereIn(
                    $related->getKeyName(),
                    ScrapedProduct::query()->select('web_site_id')->whereNotNull('web_site_id')->distinct()
                )
                ->orderBy($related->getKeyName())
                ->get(['id', 'name', 'url', 'country_code'])
                ->groupBy(fn ($w) => self::siteKey($w->url) ?: 'id:' . $w->id)
                ->map(function ($group, $key) {
                    $first = $group->first();

                    $code = $group->pluck('country_code')
                        ->map(fn ($c) => strtoupper(trim((string) $c)))
                        ->filter()
                        ->first()
                        ?? self::countryFromUrl($first->url)
                        ?? 'OTHER';

                    return [
                        'key'          => (string) $key,
                        'name'         => $first->name,
                        'url'          => $first->url,
                        'country_code' => $code,
                        'ids'          => $group->pluck('id')->all(),
                    ];
                })
                ->sortBy(fn ($w) => ($w['country_code'] === 'OTHER' ? '~' : $w['country_code']) . '|' . strtolower((string) $w['name']))
                ->values()
                ->all();
        });
    }

    /**
     * Onglets : un par pays, avec le nombre de sites.
     */
    #[Computed]
    public function countries(): array
    {
        $counts = [];

        foreach ($this->websites as $site) {
            $counts[$site['country_code']] = ($counts[$site['country_code']] ?? 0) + 1;
        }

        // Ordre alphabétique, "Autres" en dernier
        uksort($counts, fn ($a, $b) => $a === 'OTHER' ? 1 : ($b === 'OTHER' ? -1 : strcmp($a, $b)));

        return array_map(
            fn ($code, $n) => ['code' => $code, 'label' => self::COUNTRY_LABELS[$code] ?? $code, 'count' => $n],
            array_keys($counts),
            $counts
        );
    }

    /**
     * Colonnes affichées selon l'onglet actif.
     */
    #[Computed]
    public function visibleWebsites(): array
    {
        $known = collect($this->countries)->contains('code', $this->country);

        if ($this->country === 'all' || ! $known) {
            return $this->websites;
        }

        return array_values(array_filter($this->websites, fn ($w) => $w['country_code'] === $this->country));
    }

    /**
     * Clé de site : URL sans schéma, sans "www." et sans "/" final.
     */
    private static function siteKey(?string $url): string
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    private static function countryFromUrl(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return self::TLD_COUNTRIES[strtolower(substr(strrchr($host, '.') ?: '', 1))] ?? null;
    }

    /**
     * Prépare une ligne pour l'affichage : écart de prix, meilleur concurrent,
     * détection des contenances différentes (30 ml vs 100 ml...).
     */
    private function enrich(array $row): object
    {
        $ours   = (float) ($row['price'] ?? 0);
        $volume = $this->volume($row['option_value'] ?? null) ?? $this->volume($row['title'] ?? null);

        // Onglet actif : on ne garde que les concurrents des sites visibles
        // (le "meilleur concurrent" et l'écart suivent donc le pays choisi)
        $allowed = $this->country === 'all'
            ? null
            : array_flip(array_column($this->visibleWebsites, 'key'));

        $competitors = collect($row['competitors'] ?? [])
            ->filter(fn (array $c) => $allowed === null || isset($allowed[self::siteKey($c['website']['url'] ?? null)]))
            ->map(function (array $c) use ($ours, $volume) {
                $theirVolume = $this->volume($c['variation'] ?? null);

                // Contenance différente => prix non comparable
                $c['volume_mismatch'] = (bool) ($volume && $theirVolume && abs($volume - $theirVolume) > 0.01);
                $c['variation']       = trim((string) preg_replace('/\s+/', ' ', (string) ($c['variation'] ?? '')));
                $c['is_eur']          = in_array(strtoupper(trim((string) ($c['currency'] ?? ''))), self::EUR_CODES, true)
                    || trim((string) ($c['currency'] ?? '')) === '€';
                $c['diff']            = round((float) $c['prix_ht'] - $ours, 2); // > 0 : le concurrent est plus cher
                $c['scraped_label']   = ! empty($c['scraped_at']) ? Carbon::parse($c['scraped_at'])->format('d/m/Y') : null;
                $c['tone']            = match (true) {
                    $c['volume_mismatch'] => 'amber',
                    $c['diff'] < 0        => 'red',    // concurrent moins cher que nous
                    $c['diff'] > 0        => 'green',  // concurrent plus cher que nous
                    default               => 'zinc',
                };

                return $c;
            });

        // Meilleur prix concurrent : même contenance, en euros
        $best = $competitors
            ->where('volume_mismatch', false)
            ->where('is_eur', true)
            ->sortBy('prix_ht')
            ->first();

        $product = (object) $row;
        $product->competitors = $competitors->all();
        // Un relevé par site (déjà dédoublonné par l'API) : accès direct depuis la colonne du site
        $product->by_site     = $competitors
            ->groupBy(fn ($c) => self::siteKey($c['website']['url'] ?? null))
            ->map(fn ($group) => $group->sortByDesc('scraped_at')->first())
            ->all();
        $product->best_key    = $best ? self::siteKey($best['website']['url'] ?? null) : null;
        $product->best        = $best;
        $product->gap         = $best ? round($ours - (float) $best['prix_ht'], 2) : null; // > 0 : nous sommes plus chers
        $product->gap_pct     = $best && $best['prix_ht'] > 0 ? round(($ours / $best['prix_ht'] - 1) * 100, 1) : null;

        return $product;
    }

    private function volume(?string $text): ?float
    {
        if ($text && preg_match('/(\d+(?:[.,]\d+)?)\s*ml/i', $text, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }
};
