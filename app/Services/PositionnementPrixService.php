<?php

namespace App\Services;

use App\Http\Controllers\Api\ComparateurController;
use App\Jobs\BuildPositionnementDatasetJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données COMPACT pour l'analyse de positionnement prix :
 * un enregistrement par produit boutique MATCHÉ (only_matched=1) présent chez au moins
 * un concurrent. Rapprochement par EAN (déjà fait par l'API comparateur) : on compare
 * seulement notre prix (price / special_price) au prix_ht du concurrent. Aucune vérification
 * de devise (tout est en euros) ni de variation / contenance. 1 relevé par site et par produit.
 *
 * ANTI-TIMEOUT : la requête HTTP ne construit JAMAIS le jeu de données.
 *  - la construction (longue) tourne dans un Job (queue) ou via `php artisan positionnement:build`
 *  - la requête lit seulement le cache (instantané)
 *  - cache "stale-while-revalidate" : au-delà de FRESH_TTL, on sert les données et on
 *    relance une reconstruction en arrière-plan
 *  - un verrou empêche deux constructions simultanées
 */
class PositionnementPrixService
{
    public const CACHE_KEY  = 'positionnement-prix:dataset:v3'; // v3 : nouvelle structure
    public const QUEUED_KEY = 'positionnement-prix:queued';
    public const LOCK_KEY   = 'positionnement-prix:build-lock';
    public const ERROR_KEY  = 'positionnement-prix:last-error';

    public const FRESH_TTL = 1800;   // 30 min
    public const KEEP_TTL  = 86400;  // 24 h

    private const PER_PAGE = 100; // max autorisé par Boutiqueproductservice::rules()

    private const TLD_COUNTRIES = [
        'fr' => 'FR', 'de' => 'DE', 'be' => 'BE', 'nl' => 'NL', 'it' => 'IT',
        'es' => 'ES', 'pt' => 'PT', 'ch' => 'CH', 'at' => 'AT', 'lu' => 'LU', 'pl' => 'PL',
    ];

    /** Compteurs de qualité de la dernière construction */
    private array $stats = [];

    /**
     * @return array{built_at: int|null, rows: array, stats?: array}
     */
    public function dataset(): array
    {
        $data = Cache::get(self::CACHE_KEY);

        if (! is_array($data)) {
            $this->dispatchRefresh();

            return ['built_at' => null, 'rows' => [], 'stats' => []];
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

    public function dispatchRefresh(bool $force = false): bool
    {
        if ($force) {
            Cache::forget(self::QUEUED_KEY);
        }

        if (! Cache::add(self::QUEUED_KEY, 1, 900)) {
            return false;
        }

        BuildPositionnementDatasetJob::dispatch();

        return true;
    }

    public function refresh(): array
    {
        $this->dispatchRefresh(true);

        return $this->dataset();
    }

    public function lastError(): ?string
    {
        $e = Cache::get(self::ERROR_KEY);

        return is_string($e) && $e !== '' ? $e : null;
    }

    public function storeError(string $message): void
    {
        Cache::put(self::ERROR_KEY, $message, 3600);
    }

    public function forceUnlock(): void
    {
        Cache::lock(self::LOCK_KEY)->forceRelease();
        Cache::forget(self::QUEUED_KEY);
    }

    public function buildAndStore(): ?array
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);

        if (! $lock->get()) {
            Cache::forget(self::QUEUED_KEY);

            return null;
        }

        try {
            $data = $this->build();

            Cache::put(self::CACHE_KEY, $data, self::KEEP_TTL);
            Cache::forget(self::ERROR_KEY);

            return $data;
        } finally {
            Cache::forget(self::QUEUED_KEY);
            $lock->release();
        }
    }

    private function build(): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        DB::connection()->disableQueryLog();

        $this->stats = [
            'received'      => 0, // produits matchés reçus de l'API
            'kept'          => 0, // produits exploitables
            'no_price'      => 0, // notre prix absent / nul
            'no_competitor' => 0, // aucun relevé comparable
            'rel_total'     => 0, // relevés concurrents reçus
            'rel_price'     => 0, // relevés sans prix
            'rel_dupes'     => 0, // doublons (même site, même produit)
        ];

        $rows = [];
        $page = 1;
        $last = 1;

        do {
            $json = $this->fetchPage($page);
            $last = (int) ($json['meta']['last_page'] ?? 1);

            foreach ($json['data'] as $row) {
                $this->stats['received']++;

                if ($compact = $this->compact($row)) {
                    $rows[] = $compact;
                    $this->stats['kept']++;
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
            'stats'    => $this->stats,
        ];
    }

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

            sleep($attempts);
        }
    }

    /**
     * Réduit un produit de l'API à ce qui sert à l'analyse.
     * - garde notre prix catalogue ET notre prix effectif (promo)
     * - compare uniquement prix_ht du concurrent à notre prix (EAN déjà apparié par l'API)
     * - garde UN relevé par site (le plus récent, puis le moins cher)
     */
    private function compact(array $row): ?array
    {
        $regular = (float) ($row['price'] ?? 0);
        $special = (float) ($row['special_price'] ?? 0);

        // Prix réellement pratiqué : promo valide (> 0 et < prix catalogue) sinon catalogue
        $onPromo = $special > 0 && ($regular <= 0 || $special < $regular);
        $ours    = $onPromo ? $special : $regular;

        if ($ours <= 0) {
            $this->stats['no_price']++;

            return null;
        }

        $bySite = [];

        foreach ($row['competitors'] ?? [] as $c) {
            $this->stats['rel_total']++;

            $price = (float) ($c['prix_ht'] ?? 0);

            if ($price <= 0) {
                $this->stats['rel_price']++;
                continue;
            }

            $url     = $c['website']['url'] ?? null;
            $name    = (string) ($c['website']['name'] ?? '?');
            $country = strtoupper(trim((string) ($c['website']['country_code'] ?? '')))
                ?: ($this->countryFromUrl($url) ?? 'OTHER');

            $entry = [
                'key'     => $this->siteKey($url) ?: strtolower($name),
                'name'    => $name,
                'country' => $country,
                'price'   => $price,
                'ts'      => ! empty($c['scraped_at']) ? (strtotime($c['scraped_at']) ?: null) : null,
            ];

            $prev = $bySite[$entry['key']] ?? null;

            if ($prev === null) {
                $bySite[$entry['key']] = $entry;
                continue;
            }

            $this->stats['rel_dupes']++;

            if ($this->better($entry, $prev)) {
                $bySite[$entry['key']] = $entry;
            }
        }

        if (empty($bySite)) {
            $this->stats['no_competitor']++;

            return null;
        }

        return [
            'sku'         => (string) ($row['sku'] ?? ''),
            'ean'         => (string) ($row['ean'] ?? $row['barcode'] ?? $row['gtin'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'vendor'      => trim((string) ($row['vendor'] ?? '')) ?: 'Inconnue',
            'type'        => trim((string) ($row['type'] ?? '')) ?: 'Autre',
            'ours'        => $ours,      // prix effectif (promo si elle existe)
            'regular'     => $regular > 0 ? $regular : $ours, // prix catalogue
            'on_promo'    => $onPromo,
            'competitors' => array_values($bySite),
        ];
    }

    /** Un relevé est "meilleur" : plus récent, puis moins cher. */
    private function better(array $a, array $b): bool
    {
        if (($a['ts'] ?? 0) !== ($b['ts'] ?? 0)) {
            return ($a['ts'] ?? 0) > ($b['ts'] ?? 0);
        }

        return $a['price'] < $b['price'];
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
}
