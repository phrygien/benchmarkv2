<?php

namespace App\Services;

use Google\Shopping\Merchant\Reports\V1\Client\ReportServiceClient;
use Google\Shopping\Merchant\Reports\V1\SearchRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Top produits Google Shopping selon Merchant Center (Merchant API, rapport
 * product_performance_view) : clics, impressions, CTR, conversions,
 * enrichis avec les infos boutique (Magento) et les prix concurrents.
 *
 * Même principe que TopVenteService : règles de validation, filtres normalisés,
 * cache versionné, pagination + liens.
 *
 * L'API Merchant ne gère pas d'offset : on charge TOUT le rapport une fois (mis en cache),
 * puis on filtre / trie / pagine en PHP.
 *
 * Hypothèse : offer_id (Merchant) = SKU Magento = EAN.
 */
class GoogleTopProduitService
{
    public const MAGENTO_CONNECTION = 'mysqlMagento';
    public const CACHE_PREFIX = 'googlemerchant';
    public const CACHE_TTL = 3600; // 1 heure
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_SORT = 'clicks';

    // "sort" => clé de la ligne agrégée (liste blanche)
    public const SORTS = [
        'clicks'      => 'clicks',
        'impressions' => 'impressions',
        'conversions' => 'conversions',
    ];

    private const EUR_CODES = ['EUR', '€'];

    /*
    |--------------------------------------------------------------------------
    | API publique
    |--------------------------------------------------------------------------
    */

    public static function rules(): array
    {
        return [
            'country'             => ['nullable', 'string', 'size:2'],
            'date_from'           => ['nullable', 'date_format:Y-m-d'],
            'date_to'             => ['nullable', 'date_format:Y-m-d'],
            'sort'                => ['nullable', 'in:' . implode(',', array_keys(self::SORTS))],
            'groupe'              => ['nullable'],
            'groupe.*'            => ['string', 'max:255'],
            'page'                => ['nullable', 'integer', 'min:1'],
            'per_page'            => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'with_competitors'    => ['nullable', 'boolean'],
            'competitors_country' => ['nullable', 'string', 'max:3'], // code pays ou "all"
        ];
    }

    public function filtersFrom(Request $request): array
    {
        $year  = date('Y');
        $today = date('Y-m-d');

        $from = $request->input('date_from') ?: "{$year}-01-01";
        $to   = $request->input('date_to') ?: "{$year}-12-31";

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // Pas de données dans le futur : on borne la fin à aujourd'hui
        $to   = min($to, $today);
        $from = min($from, $to);

        $groupes = $request->input('groupe', []);
        $groupes = is_array($groupes) ? $groupes : [$groupes];
        $groupes = array_values(array_unique(array_filter(
            array_map(fn ($g) => trim((string) $g), $groupes),
            fn ($g) => $g !== ''
        )));
        sort($groupes);

        $sort = (string) $request->input('sort');

        return [
            'country'   => strtoupper((string) ($request->input('country') ?: 'FR')),
            'date_from' => $from,
            'date_to'   => $to,
            'sort'      => array_key_exists($sort, self::SORTS) ? $sort : self::DEFAULT_SORT,
            'groupes'   => $groupes,
        ];
    }

    /**
     * Une page de top produits (mise en cache).
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        return Cache::remember(
            $this->cacheKey('items', $filters, $page, $perPage),
            self::CACHE_TTL,
            fn () => $this->fetch($filters, $page, $perPage)
        );
    }

    /**
     * Marques (Merchant "brand") présentes sur la période / le pays, pour alimenter un filtre.
     *
     * @return string[]
     */
    public function groupes(array $filters): array
    {
        $brands = array_unique(array_filter(array_column($this->rawRows($filters), 'brand')));
        sort($brands, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($brands);
    }

    /**
     * Ajoute prix concurrents + marché à chaque ligne (non mis en cache : prix toujours frais).
     *
     * @param  array[]      $rows
     * @param  string|null  $competitorsCountry  code pays ou null/"all" = tous
     */
    public function withCompetitors(array $rows, ?string $competitorsCountry, CompetitorPriceService $competitorPrices): array
    {
        $country = ($competitorsCountry === null || strtolower($competitorsCountry) === 'all')
            ? null
            : strtoupper($competitorsCountry);

        $comparisons = $competitorPrices->forEans(array_column($rows, 'ean'), $country);

        return array_map(function (array $row) use ($comparisons, $competitorPrices) {
            $comparison = $comparisons[$row['ean']] ?? $competitorPrices->emptyComparison();

            $row['competitors']         = $comparison['competitors'];
            $row['competitors_summary'] = $comparison['summary'];
            $row['market']              = $this->market($comparison['competitors'], (float) ($row['prix_vente_cosma'] ?? 0));

            return $row;
        }, $rows);
    }

    public function clearCache(): void
    {
        $key = self::CACHE_PREFIX . ':version';
        Cache::add($key, 1);
        Cache::increment($key);
    }

    public function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_PREFIX . ':version', 1);
    }

    public function paginationLinks(Request $request, int $currentPage, int $lastPage): array
    {
        $url   = $request->url();
        $query = $request->except('page');

        $link = fn (int $p) => $url . '?' . http_build_query($query + ['page' => $p]);

        return [
            'first' => $link(1),
            'last'  => $link(max(1, $lastPage)),
            'prev'  => $currentPage > 1 ? $link($currentPage - 1) : null,
            'next'  => $currentPage < $lastPage ? $link($currentPage + 1) : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération des données
    |--------------------------------------------------------------------------
    */

    private function fetch(array $filters, int $page, int $perPage): array
    {
        $rows = $this->rawRows($filters);

        // Filtre marque (Merchant "brand")
        if (! empty($filters['groupes'])) {
            $wanted = array_map('mb_strtolower', $filters['groupes']);
            $rows   = array_values(array_filter(
                $rows,
                fn ($r) => in_array(mb_strtolower((string) $r['brand']), $wanted, true)
            ));
        }

        // Tri décroissant sur la métrique choisie (ean en second critère : ordre stable)
        $metric = self::SORTS[$filters['sort']] ?? self::SORTS[self::DEFAULT_SORT];
        usort($rows, fn ($a, $b) => [$b[$metric], $a['ean']] <=> [$a[$metric], $b['ean']]);

        $total    = count($rows);
        $lastPage = (int) ceil($total / $perPage);

        if ($lastPage > 0 && $page > $lastPage) {
            $page = 1;
        }

        $offset   = ($page - 1) * $perPage;
        $slice    = array_slice($rows, $offset, $perPage);
        $products = $this->magentoProducts(array_column($slice, 'ean'));

        $data = [];

        foreach ($slice as $i => $item) {
            $p = $products[$item['ean']] ?? [];

            $data[] = [
                'ean'                 => $item['ean'],
                'country'             => $filters['country'],
                'groupe'              => $p['groupe'] ?? $item['brand'],
                'marque'              => $p['marque'] ?? $item['title'],
                'designation_produit' => $p['designation_produit'] ?? $item['title'],
                'prix_vente_cosma'    => $p['prix_vente_cosma'] ?? null,
                'cost'                => $p['cost'] ?? null,
                'pght'                => $p['pght'] ?? null,
                'clicks'              => $item['clicks'],
                'impressions'         => $item['impressions'],
                'click_through_rate'  => $item['click_through_rate'], // ratio (0.034 = 3,4 %)
                'conversions'         => $item['conversions'],
                'conversion_value'    => $item['conversion_value'],
                'rank'                => $offset + $i + 1, // position selon le tri demandé
                'merchant_title'      => $item['title'],
                'merchant_brand'      => $item['brand'],
            ];
        }

        return [
            'total_item'   => $total,
            'per_page'     => $perPage,
            'total_page'   => $lastPage,
            'current_page' => $page,
            'data'         => $data,
            'cached_at'    => now()->toDateTimeString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Google Merchant (Merchant API - Reports)
    |--------------------------------------------------------------------------
    */

    /**
     * Rapport complet (pays + période), agrégé par offer_id. Mis en cache.
     * Ne dépend ni du tri ni du filtre marque.
     *
     * @return array[]
     */
    private function rawRows(array $filters): array
    {
        return Cache::remember(
            $this->cacheKey('raw', array_diff_key($filters, ['sort' => 1, 'groupes' => 1])),
            self::CACHE_TTL,
            fn () => $this->queryAll($filters)
        );
    }

    private function queryAll(array $filters): array
    {
        // Pays / dates déjà validés (format), on nettoie quand même avant de les mettre dans la requête MCQL
        $country = preg_replace('/[^A-Z]/', '', strtoupper($filters['country']));
        $from    = preg_replace('/[^0-9-]/', '', $filters['date_from']);
        $to      = preg_replace('/[^0-9-]/', '', $filters['date_to']);

        $query = "SELECT offer_id, title, brand, clicks, impressions, click_through_rate, conversions, conversion_value "
            . "FROM product_performance_view "
            . "WHERE date BETWEEN '{$from}' AND '{$to}' "
            . "AND customer_country_code = '{$country}'";

        $request = (new SearchRequest())
            ->setParent('accounts/' . $this->accountId())
            ->setQuery($query)
            ->setPageSize(1000);

        $byOffer = [];

        // Le client pagine tout seul (next_page_token)
        foreach ($this->client()->search($request) as $reportRow) {
            $view = $reportRow->getProductPerformanceView();

            if (! $view) {
                continue;
            }

            $offerId = (string) $view->getOfferId();

            if ($offerId === '') {
                continue;
            }

            // title / brand reflètent l'état du produit à l'impression : plusieurs lignes possibles par offer_id
            $byOffer[$offerId] ??= [
                'ean'              => $offerId,
                'title'            => $this->utf8($view->getTitle()),
                'brand'            => $this->utf8($view->getBrand()),
                'clicks'           => 0,
                'impressions'      => 0,
                'conversions'      => 0.0,
                'conversion_value' => 0.0,
            ];

            $byOffer[$offerId]['clicks']      += (int) $view->getClicks();
            $byOffer[$offerId]['impressions'] += (int) $view->getImpressions();
            $byOffer[$offerId]['conversions'] += (float) $view->getConversions();

            if ($view->hasConversionValue()) {
                $byOffer[$offerId]['conversion_value'] += ((int) $view->getConversionValue()->getAmountMicros()) / 1_000_000;
            }
        }

        return array_values(array_map(function (array $r) {
            $r['click_through_rate'] = $r['impressions'] > 0 ? round($r['clicks'] / $r['impressions'], 4) : 0.0;
            $r['conversions']        = round($r['conversions'], 2);
            $r['conversion_value']   = round($r['conversion_value'], 2);

            return $r;
        }, $byOffer));
    }

    private function client(): ReportServiceClient
    {
        $credentials = config('services.google_merchant.credentials');

        if (empty($credentials['private_key']) || empty($credentials['client_email'])) {
            throw new RuntimeException('Google Merchant : identifiants du compte de service manquants (GOOGLE_PRIVATE_KEY / GOOGLE_CLIENT_EMAIL).');
        }

        return new ReportServiceClient(['credentials' => $credentials]);
    }

    private function accountId(): string
    {
        $id = config('services.google_merchant.account_id');

        if (! $id) {
            throw new RuntimeException('Google Merchant : GOOGLE_MERCHANT_ID non configuré.');
        }

        return (string) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Boutique (Magento) : nom, groupe, prix de vente, coût
    |--------------------------------------------------------------------------
    */

    /**
     * Même découpage du nom produit et même calcul de prix que TopVenteService::salesCte().
     *
     * @param  string[]  $skus
     * @return array<string, array>  indexé par SKU
     */
    private function magentoProducts(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));

        if (empty($skus)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '?'));

        $rows = DB::connection(self::MAGENTO_CONNECTION)->select("
            SELECT
                produit.sku AS ean,
                SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe,
                SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 2), ' - ', -1) AS marque,
                SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 3), ' - ', -1) AS designation_produit,
                (CASE
                    WHEN ROUND(product_decimal.special_price, 2) IS NOT NULL THEN ROUND(product_decimal.special_price, 2)
                    ELSE ROUND(product_decimal.price, 2)
                END) AS prix_vente_cosma,
                ROUND(product_decimal.cost, 2) AS cost,
                ROUND(product_decimal.prix_achat_ht, 2) AS pght
            FROM catalog_product_entity AS produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
            WHERE produit.sku IN ({$placeholders})
        ", $skus);

        $num = fn ($v) => $v === null ? null : (float) $v;

        $result = [];

        foreach ($rows as $row) {
            $r   = (array) $row;
            $sku = (string) $r['ean'];

            if (isset($result[$sku])) {
                continue; // une seule ligne par SKU
            }

            $result[$sku] = [
                'groupe'              => $this->utf8($r['groupe'] ?? null),
                'marque'              => $this->utf8($r['marque'] ?? null),
                'designation_produit' => $this->utf8($r['designation_produit'] ?? null),
                'prix_vente_cosma'    => $num($r['prix_vente_cosma'] ?? null),
                'cost'                => $num($r['cost'] ?? null),
                'pght'                => $num($r['pght'] ?? null),
            ];
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Prix concurrents : marché
    |--------------------------------------------------------------------------
    */

    /**
     * Même logique que l'API top-ventes : un relevé par site, sites en euros uniquement,
     * diff = moyenne concurrents − notre prix.
     */
    private function market(array $competitors, float $ours): array
    {
        $perSite = collect($competitors)
            ->groupBy(fn ($c) => $this->siteKey($c['website']['url'] ?? null))
            ->map(fn ($group) => $group->sortByDesc('scraped_at')->first())
            ->filter(fn ($c) => in_array(strtoupper(trim((string) ($c['currency'] ?? ''))), self::EUR_CODES, true));

        if ($perSite->isEmpty()) {
            return [
                'count' => 0, 'min' => null, 'max' => null, 'avg' => null,
                'diff' => null, 'diff_pct' => null, 'best' => null,
            ];
        }

        $best = $perSite->sortBy('prix_ht')->first();
        $avg  = round((float) $perSite->avg('prix_ht'), 2);
        $diff = $ours > 0 ? round($avg - $ours, 2) : null;

        return [
            'count'    => $perSite->count(),
            'min'      => round((float) $perSite->min('prix_ht'), 2),
            'max'      => round((float) $perSite->max('prix_ht'), 2),
            'avg'      => $avg,
            'diff'     => $diff,
            'diff_pct' => ($diff !== null) ? round($diff / $ours * 100, 2) : null,
            'best'     => [
                'website' => $best['website']['name'] ?? null,
                'prix_ht' => $best['prix_ht'],
                'url'     => $best['url'],
            ],
        ];
    }

    private function siteKey(?string $url): string
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);

        return rtrim($url, '/');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function cacheKey(string $type, array $filters, ...$params): string
    {
        return sprintf(
            '%s:v%d:%s:%s:%s',
            self::CACHE_PREFIX,
            $this->cacheVersion(),
            $type,
            md5(json_encode($filters)),
            implode(':', $params)
        );
    }

    /**
     * Force une chaîne en UTF-8 valide (les valeurs invalides sont supposées en Windows-1252).
     */
    private function utf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
