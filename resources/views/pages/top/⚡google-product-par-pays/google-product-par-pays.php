<?php

use App\Http\Controllers\Api\GoogletopproduitController;
use App\Jobs\ExportGoogleTopProduitsJob;
use App\Models\ScrapedProduct;
use App\Services\GoogleTopProduitService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Au-delà de ce nombre de jours, un relevé est signalé comme ancien (orange)
    private const STALE_DAYS = 7;

    private const COUNTRIES = [
        'FR' => 'France',
        'DE' => 'Allemagne',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'IT' => 'Italie',
        'ES' => 'Espagne',
    ];

    #[Url]
    public string $country = 'FR';

    #[Url]
    public string $sort = 'clicks'; // clicks | impressions | conversions

    #[Url]
    public string $groupe = ''; // marque Merchant

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 25;

    public ?string $error = null;

    // Jeton de l'export Excel en cours (suivi de progression dans le cache)
    public ?string $exportToken = null;

    // false tant que la page n'a pas fini son premier affichage
    public bool $loaded = false;

    public function mount(): void
    {
        $this->dateFrom = $this->dateFrom ?: date('Y') . '-01-01';
        $this->dateTo   = $this->dateTo ?: min(date('Y') . '-12-31', date('Y-m-d'));
    }

    public function load(): void
    {
        $this->loaded = true;
    }

    // Tout changement de filtre => retour page 1
    public function updated(string $property): void
    {
        if (! str_contains($property, 'page')) {
            $this->resetPage();
        }

        // La liste des marques dépend du pays / de la période
        if (in_array($property, ['country', 'dateFrom', 'dateTo'], true)) {
            $this->groupe = '';
        }
    }

    /**
     * Lance l'export Excel en tâche de fond.
     * $all = false : page affichée ; true : tous les produits correspondant aux filtres.
     */
    public function export(bool $all = false): void
    {
        $request = Request::create(url('/api/google-top-produits'), 'GET', $this->filters());
        $filters = app(GoogleTopProduitService::class)->filtersFrom($request);

        $this->exportToken = (string) Str::uuid();

        Cache::put(ExportGoogleTopProduitsJob::statusKey($this->exportToken), ['state' => 'queued'], 3600);

        ExportGoogleTopProduitsJob::dispatch($this->exportToken, $filters, $all, $this->getPage(), $this->perPage);
    }

    #[Computed]
    public function exportStatus(): ?array
    {
        return $this->exportToken
            ? Cache::get(ExportGoogleTopProduitsJob::statusKey($this->exportToken))
            : null;
    }

    public function dismissExport(): void
    {
        $this->exportToken = null;
    }

    public function download()
    {
        $status = $this->exportStatus;

        abort_unless(
            ($status['state'] ?? null) === 'done' && Storage::disk('local')->exists($status['path']),
            404
        );

        return Storage::disk('local')->download($status['path'], $status['name']);
    }

    #[Computed]
    public function countries(): array
    {
        return self::COUNTRIES;
    }

    private function filters(): array
    {
        return array_filter([
            'country'             => $this->country,
            'date_from'           => $this->dateFrom,
            'date_to'             => $this->dateTo,
            'sort'                => $this->sort,
            'groupe'              => $this->groupe !== '' ? [$this->groupe] : null,
            'with_competitors'    => 1,
            'competitors_country' => $this->country,
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Marques Merchant pour le filtre (cache du service).
     */
    #[Computed]
    public function groupes(): array
    {
        if (! $this->loaded) {
            return [];
        }

        try {
            $service = app(GoogleTopProduitService::class);
            $request = Request::create(url('/api/google-top-produits/groupes'), 'GET', $this->filters());

            return $service->groupes($service->filtersFrom($request));
        } catch (\Throwable $e) {
            Log::error('Top Google (Livewire) groupes : ' . $e->getMessage());

            return [];
        }
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $page = $this->getPage();
        $this->error = null;

        $options = ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'];

        // Premier rendu : page vide instantanée, les données arrivent via wire:init
        if (! $this->loaded) {
            return new LengthAwarePaginator([], 0, $this->perPage, $page, $options);
        }

        $query = $this->filters() + ['page' => $page, 'per_page' => $this->perPage];

        $response = [];

        try {
            // Appel direct du contrôleur : pas de requête HTTP vers soi-même
            $apiRequest   = Request::create(url('/api/google-top-produits'), 'GET', $query);
            $jsonResponse = app(GoogletopproduitController::class)->index($apiRequest);
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
            Log::error('Top Google (Livewire) : ' . $e->getMessage(), ['exception' => $e]);

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
     * Sites concurrents (une entrée par site réel, doublons fusionnés sur l'URL normalisée).
     */
    #[Computed]
    public function websites(): array
    {
        return Cache::remember('top-ventes:websites:v1', 600, function () {
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
                    ];
                })
                ->sortBy(fn ($w) => strtolower((string) $w['name']))
                ->values()
                ->all();
        });
    }

    /**
     * Colonnes affichées : les sites concurrents du pays de l'onglet actif.
     */
    #[Computed]
    public function visibleWebsites(): array
    {
        return array_values(array_filter(
            $this->websites,
            fn ($w) => $w['country_code'] === $this->country
        ));
    }

    private static function siteKey(?string $url): string
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    private static function countryFromUrl(?string $url): ?string
    {
        $tlds = ['fr' => 'FR', 'de' => 'DE', 'be' => 'BE', 'nl' => 'NL', 'it' => 'IT', 'es' => 'ES'];
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return $tlds[strtolower(substr(strrchr($host, '.') ?: '', 1))] ?? null;
    }

    /**
     * Parse une date ISO 8601 de l'API et la convertit dans le fuseau de l'application.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Prépare une ligne : un relevé par site (le plus récent), ton selon l'écart,
     * date de dernière mise à jour du prix.
     */
    private function enrich(array $row): object
    {
        $ours       = (float) ($row['prix_vente_cosma'] ?? 0);
        $staleLimit = now()->subDays(self::STALE_DAYS);

        $competitors = collect($row['competitors'] ?? [])
            ->groupBy(fn ($c) => self::siteKey($c['website']['url'] ?? null))
            ->map(fn ($g) => $g->sortByDesc('scraped_at')->first())
            ->map(function (array $c) use ($ours, $staleLimit) {
                // Sans prix boutique (produit absent de Magento) : pas de comparaison possible
                $diff      = $ours > 0 ? round((float) $c['prix_ht'] - $ours, 2) : null;
                $scrapedAt = $this->parseDate($c['scraped_at'] ?? null);

                $c['site_key'] = self::siteKey($c['website']['url'] ?? null);
                $c['diff']     = $diff;
                $c['tone']     = $diff === null || $diff == 0 ? 'zinc' : ($diff < 0 ? 'red' : 'green');

                // Date du relevé (dernière mise à jour du prix chez ce concurrent)
                $c['scraped_label'] = $scrapedAt?->format('d/m/Y');
                $c['scraped_full']  = $scrapedAt?->format('d/m/Y H:i');
                // Relevé ancien => date en orange dans la vue
                $c['is_stale']      = $scrapedAt !== null && $scrapedAt->lt($staleLimit);

                return $c;
            })
            ->sortBy('prix_ht')
            ->values();

        $product = (object) $row;
        $product->competitors = $competitors->all();
        $product->by_site     = $competitors->keyBy('site_key')->all();

        // Pas d'image côté Merchant : on prend celle du premier concurrent qui en a une
        $product->image_url = $row['image_url']
            ?? $competitors->pluck('image_url')->filter()->first();

        return $product;
    }
};
?>
