<?php

namespace App\Services;

use App\Http\Controllers\Api\ComparateurController;
use App\Jobs\BuildPositionnementDatasetJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données COMPACT pour l'analyse de positionnement prix :
 * un enregistrement par produit boutique présent chez au moins un concurrent,
 * avec uniquement les relevés comparables (euros, même contenance).
 *
 * ANTI-TIMEOUT : la requête HTTP ne construit JAMAIS le jeu de données.
 *  - la construction (longue) tourne dans un Job (queue) ou via `php artisan positionnement:build`
 *  - la requête lit seulement le cache (instantané)
 *  - cache "stale-while-revalidate" : si les données ont plus de FRESH_TTL, on les sert quand
 *    même et on relance une reconstruction en arrière-plan
 *  - un verrou empêche deux constructions simultanées
 */
class PositionnementPrixService
{
    public const CACHE_KEY  = 'positionnement-prix:dataset:v2';
    public const QUEUED_KEY = 'positionnement-prix:queued';
    public const LOCK_KEY   = 'positionnement-prix:build-lock';
    public const ERROR_KEY  = 'positionnement-prix:last-error';

    public const FRESH_TTL = 1800;   // 30 min : au-delà, on relance une reconstruction
    public const KEEP_TTL  = 86400;  // 24 h : durée de conservation (données "stale" servies)

    private const PER_PAGE = 100; // max autorisé par Boutiqueproductservice::rules()

    // Le scraping renvoie "€", "EUR" ou parfois "E"
    private const EUR_CODES = ['EUR', '€', 'E'];

    private const TLD_COUNTRIES = [
        'fr' => 'FR', 'de' => 'DE', 'be' => 'BE', 'nl' => 'NL', 'it' => 'IT',
        'es' => 'ES', 'pt' => 'PT', 'ch' => 'CH', 'at' => 'AT', 'lu' => 'LU', 'pl' => 'PL',
    ];

    /**
     * Lit le cache uniquement (jamais de construction inline).
     * Si absent ou périmé, déclenche une reconstruction en arrière-plan.
     * Retourne built_at = null et rows = [] tant que rien n'est prêt.
     *
     * @return array{built_at: int|null, rows: array}
     */
    public function dataset(): array
    {
        $data = Cache::get(self::CACHE_KEY);

        if (! is_array($data)) {
            $this->dispatchRefresh();

            return ['built_at' => null, 'rows' => []];
        }

        if (time() - (int) ($data['built_at'] ?? 0) > self::FRESH_TTL) {
            $this->dispatchRefresh();
        }

        return $data;
    }

    public function isStale(?int $builtAt): bool
    {
        return $builtAt === null || (time() - $builtAt) > self::FRESH_TTL;
    }

    /**
     * Demande une reconstruction en arrière-plan (sans bloquer la requête).
     * Retourne false si une reconstruction est déjà demandée / en cours.
     */
    public function dispatchRefresh(bool $force = false): bool
    {
        if ($force) {
            Cache::forget(self::QUEUED_KEY);
        }

        // Anti-doublon : un seul job en file à la fois
        if (! Cache::add(self::QUEUED_KEY, 1, 900)) {
            return false;
        }

        BuildPositionnementDatasetJob::dispatch();

        return true;
    }

    /**
     * Compatibilité (ex. bouton "Actualiser" Livewire) : relance en arrière-plan,
     * retourne immédiatement les données actuelles (éventuellement vides).
     */
    public function refresh(): array
    {
        $this->dispatchRefresh(true);

        return $this->dataset();
    }

    /**
     * Dernière erreur d'un job de construction (effacée dès qu'une construction réussit).
     */
    public function lastError(): ?string
    {
        $e = Cache::get(self::ERROR_KEY);

        return is_string($e) && $e !== '' ? $e : null;
    }

    public function storeError(string $message): void
    {
        Cache::put(self::ERROR_KEY, $message, 3600);
    }

    /**
     * Libère un verrou resté bloqué (ex. commande interrompue par Ctrl+C).
     */
    public function forceUnlock(): void
    {
        Cache::lock(self::LOCK_KEY)->forceRelease();
        Cache::forget(self::QUEUED_KEY);
    }

    /**
     * Construit et stocke le jeu de données. À appeler depuis le Job / la commande uniquement.
     * Retourne null si une autre construction est déjà en cours.
     */
    public function buildAndStore(): ?array
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);

        if (! $lock->get()) {
            Cache::forget(self::QUEUED_KEY);

            return null;
        }

        try {
            $data = $this->build();

            // On écrase seulement à la fin : les anciennes données restent servies pendant le calcul
            Cache::put(self::CACHE_KEY, $data, self::KEEP_TTL);
            Cache::forget(self::ERROR_KEY);

            return $data;
        } finally {
            Cache::forget(self::QUEUED_KEY);
            $lock->release();
        }
    }

    /**
     * Parcourt toutes les pages de /api/comparateur (only_matched=1) en appelant
     * directement le contrôleur, puis ne garde que l'essentiel de chaque produit.
     */
    private function build(): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Évite que le log de requêtes SQL grossisse à chaque page (fuite mémoire classique)
        DB::connection()->disableQueryLog();

        $rows = [];
        $page = 1;
        $last = 1;

        do {
            $json = $this->fetchPage($page);

            $last = (int) ($json['meta']['last_page'] ?? 1);

            foreach ($json['data'] as $row) {
                if ($compact = $this->compact($row)) {
                    $rows[] = $compact;
                }
            }

            unset($json);
            $page++;

            if ($page % 10 === 0) {
                gc_collect_cycles();
            }
        } while ($page <= $last);

        return [
            'built_at' => time(),
            'rows'     => $rows,
        ];
    }

    /**
     * Une page de l'API comparateur, avec 2 nouvelles tentatives en cas d'erreur transitoire.
     */
    private function fetchPage(int $page): array
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            $request  = Request::create('/api/comparateur', 'GET', [
                'only_matched' => 1,
                'page'         => $page,
                'per_page'     => self::PER_PAGE,
            ]);
            $response = app(ComparateurController::class)->index($request);
            $json     = $response->getData(true);

            if ($response->getStatusCode() < 400 && is_array($json) && isset($json['data'], $json['meta'])) {
                return $json;
            }

            if ($attempts >= 3) {
                throw new \RuntimeException(
                    ($json['message'] ?? 'Réponse inattendue de l\'API comparateur')
                    . (! empty($json['error']) ? ' : ' . $json['error'] : '')
                    . " (page {$page})"
                );
            }

            sleep($attempts); // 1 s puis 2 s
        }
    }

    /**
     * Réduit un produit de l'API à ce qui sert à l'analyse.
     * Retourne null si le produit n'a aucun relevé concurrent comparable.
     */
    private function compact(array $row): ?array
    {
        // Prix réellement pratiqué : promo si elle existe, sinon prix catalogue
        $ours = (float) ($row['special_price'] ?? 0);

        if ($ours <= 0) {
            $ours = (float) ($row['price'] ?? 0);
        }

        if ($ours <= 0) {
            return null;
        }

        $volume = $this->volume($row['option_value'] ?? null) ?? $this->volume($row['title'] ?? null);

        $competitors = [];

        foreach ($row['competitors'] ?? [] as $c) {
            $price = (float) ($c['prix_ht'] ?? 0);

            if ($price <= 0) {
                continue;
            }

            // Euros uniquement
            $currency = strtoupper(trim((string) ($c['currency'] ?? '')));

            if (! in_array($currency, self::EUR_CODES, true)) {
                continue;
            }

            // Contenance différente => non comparable
            $theirVolume = $this->volume($c['variation'] ?? null);

            if ($volume && $theirVolume && abs($volume - $theirVolume) > 0.01) {
                continue;
            }

            $url     = $c['website']['url'] ?? null;
            $country = strtoupper(trim((string) ($c['website']['country_code'] ?? '')))
                ?: ($this->countryFromUrl($url) ?? 'OTHER');

            $competitors[] = [
                'key'     => $this->siteKey($url),
                'name'    => (string) ($c['website']['name'] ?? '?'),
                'country' => $country,
                'price'   => $price,
                'ts'      => ! empty($c['scraped_at']) ? (strtotime($c['scraped_at']) ?: null) : null,
            ];
        }

        if (empty($competitors)) {
            return null;
        }

        return [
            'sku'         => (string) ($row['sku'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'vendor'      => trim((string) ($row['vendor'] ?? '')) ?: 'Inconnue',
            'type'        => trim((string) ($row['type'] ?? '')) ?: 'Autre',
            'ours'        => $ours,
            'competitors' => $competitors,
        ];
    }

    private function siteKey(?string $url): string
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    private function countryFromUrl(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return self::TLD_COUNTRIES[strtolower(substr(strrchr($host, '.') ?: '', 1))] ?? null;
    }

    private function volume(?string $text): ?float
    {
        if ($text && preg_match('/(\d+(?:[.,]\d+)?)\s*ml/i', $text, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }
}
