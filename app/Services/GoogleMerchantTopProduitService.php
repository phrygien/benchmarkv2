<?php

namespace App\Services;

use Google\Shopping\Merchant\Reports\V1\Client\ReportServiceClient;
use Google\Shopping\Merchant\Reports\V1\SearchRequest;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Top produits Google Shopping selon Merchant Center (Merchant API, rapport
 * product_performance_view) : clics, impressions, CTR, conversions.
 *
 * Réutilise tout le reste du service GA4 (validation, filtres, cache versionné,
 * enrichissement Magento, prix concurrents, liens de pagination).
 *
 * L'API Merchant ne gère pas d'offset : on charge TOUT le rapport une fois (mis en cache),
 * puis on trie / filtre / pagine en PHP.
 *
 * Hypothèse : offer_id (Merchant) = SKU Magento = EAN.
 */
class GoogleMerchantTopProduitService extends GoogleTopProduitService
{
    public const CACHE_PREFIX = 'googlemerchant';

    public const DEFAULT_SORT = 'clicks';

    // "sort" => clé de la ligne agrégée (liste blanche)
    public const SORTS = [
        'clicks'      => 'clicks',
        'impressions' => 'impressions',
        'conversions' => 'conversions',
    ];

    /**
     * Marques (Merchant) présentes sur la période / le pays, pour alimenter un filtre.
     *
     * @return string[]
     */
    public function groupes(array $filters): array
    {
        $brands = array_unique(array_filter(array_column($this->rawRows($filters), 'brand')));
        sort($brands, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($brands);
    }

    protected function fetch(array $filters, int $page, int $perPage): array
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
        $metric = static::SORTS[$filters['sort']] ?? static::SORTS[static::DEFAULT_SORT];
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
        $credentials = config('services.google_merchant.credentials')
            ?: config('services.google_analytics.credentials');

        if (! $credentials) {
            throw new RuntimeException('Google Merchant : services.google_merchant.credentials non configuré.');
        }

        return new ReportServiceClient(['credentials' => $credentials]);
    }

    private function accountId(): string
    {
        $id = config('services.google_merchant.account_id');

        if (! $id) {
            throw new RuntimeException('Google Merchant : services.google_merchant.account_id non configuré.');
        }

        return (string) $id;
    }
}
