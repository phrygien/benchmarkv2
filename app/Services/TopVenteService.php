<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Top ventes de la boutique (base Magento) : quantités / CA par EAN, par pays de livraison.
 * Même principe que Boutiqueproductservice : requêtes paramétrées + cache versionné.
 */
class TopVenteService
{
    public const CONNECTION = 'mysqlMagento';
    public const CACHE_PREFIX = 'topvente';
    public const CACHE_TTL = 3600; // 1 heure
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;

    // Valeurs autorisées pour "sort" => colonne SQL (liste blanche : jamais d'entrée brute dans ORDER BY)
    public const SORTS = [
        'qty' => 'total_qty_sold',
        'ca'  => 'total_revenue',
    ];

    public const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;

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
            'sort'                => ['nullable', 'in:qty,ca'],
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
        $year = date('Y');
        $from = $request->input('date_from') ?: "{$year}-01-01";
        $to   = $request->input('date_to') ?: "{$year}-12-31";

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $groupes = $request->input('groupe', []);
        $groupes = is_array($groupes) ? $groupes : [$groupes];
        $groupes = array_values(array_unique(array_filter(
            array_map(fn ($g) => trim((string) $g), $groupes),
            fn ($g) => $g !== ''
        )));
        sort($groupes);

        return [
            'country'   => strtoupper((string) ($request->input('country') ?: 'FR')),
            'date_from' => $from,
            'date_to'   => $to,
            'sort'      => $request->input('sort') === 'ca' ? 'ca' : 'qty',
            'groupes'   => $groupes,
        ];
    }

    /**
     * Une page de top ventes (mise en cache).
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        return Cache::remember(
            $this->cacheKey('sales', $filters, $page, $perPage),
            self::CACHE_TTL,
            fn () => $this->fetch($filters, $page, $perPage)
        );
    }

    /**
     * Liste des groupes (vendors) vendus sur la période / le pays (pour alimenter un filtre).
     *
     * @return string[]
     */
    public function groupes(array $filters): array
    {
        return Cache::remember(
            $this->cacheKey('groupes', array_diff_key($filters, ['sort' => 1, 'groupes' => 1])),
            self::CACHE_TTL,
            function () use ($filters) {
                $rows = DB::connection(self::CONNECTION)->select("
                    SELECT DISTINCT
                        SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe
                    FROM sales_order_item oi
                    JOIN sales_order o ON oi.order_id = o.entity_id
                    JOIN sales_order_address addr ON addr.parent_id = o.entity_id
                        AND addr.address_type = 'shipping'
                    JOIN catalog_product_entity AS produit ON oi.sku = produit.sku
                    LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                    WHERE o.state IN ('processing', 'complete')
                      AND o.created_at >= ?
                      AND o.created_at <= ?
                      AND addr.country_id = ?
                      AND oi.row_total > 0
                    ORDER BY groupe ASC
                ", $this->baseParams($filters));

                return collect($rows)
                    ->pluck('groupe')
                    ->map(fn ($g) => $this->utf8($g))
                    ->filter(fn ($g) => $g !== null && $g !== '')
                    ->unique()
                    ->values()
                    ->all();
            }
        );
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
        [$groupeSql, $groupeParams] = $this->groupeClause($filters['groupes']);
        $baseParams = $this->baseParams($filters);

        // Le total ne dépend ni de la page ni du tri
        $total = Cache::remember(
            $this->cacheKey('count', array_diff_key($filters, ['sort' => 1])),
            self::CACHE_TTL,
            function () use ($groupeSql, $baseParams, $groupeParams) {
                $row = DB::connection(self::CONNECTION)->selectOne(
                    "WITH {$this->salesCte()} SELECT COUNT(*) AS nb FROM sales {$groupeSql}",
                    array_merge($baseParams, $groupeParams)
                );

                return (int) ($row->nb ?? 0);
            }
        );

        $lastPage = (int) ceil($total / $perPage);

        if ($lastPage > 0 && $page > $lastPage) {
            $page = 1;
        }

        $orderCol = self::SORTS[$filters['sort']] ?? self::SORTS['qty'];

        // Les rangs sont calculés sur TOUT le pays/période, puis le filtre groupe est appliqué
        // (un produit garde donc son rang global même si on filtre par vendor).
        $rows = DB::connection(self::CONNECTION)->select("
            WITH {$this->salesCte()},
            ranked_sales AS (
                SELECT
                    sales.*,
                    ROW_NUMBER() OVER (ORDER BY total_qty_sold DESC, ean ASC) AS rank_qty,
                    ROW_NUMBER() OVER (ORDER BY total_revenue DESC, ean ASC)  AS rank_ca
                FROM sales
            )
            SELECT * FROM ranked_sales
            {$groupeSql}
            ORDER BY {$orderCol} DESC, ean ASC
            LIMIT ? OFFSET ?
        ", array_merge($baseParams, $groupeParams, [$perPage, ($page - 1) * $perPage]));

        return [
            'total_item'   => $total,
            'per_page'     => $perPage,
            'total_page'   => $lastPage,
            'current_page' => $page,
            'data'         => array_map(fn ($row) => $this->sanitizeRow($row), $rows),
            'cached_at'    => now()->toDateTimeString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SQL
    |--------------------------------------------------------------------------
    */

    /**
     * CTE "sales" : une ligne par SKU (= EAN) vendu, avec quantités et CA.
     * Paramètres attendus (dans l'ordre) : date début, date fin, pays.
     */
    private function salesCte(): string
    {
        return "
            sales AS (
                SELECT
                    addr.country_id AS country,
                    oi.sku AS ean,
                    SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 2), ' - ', -1) AS marque,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 3), ' - ', -1) AS designation_produit,
                    (CASE
                        WHEN ROUND(product_decimal.special_price, 2) IS NOT NULL THEN ROUND(product_decimal.special_price, 2)
                        ELSE ROUND(product_decimal.price, 2)
                    END) AS prix_vente_cosma,
                    ROUND(product_decimal.cost, 2) AS cost,
                    ROUND(product_decimal.prix_achat_ht, 2) AS pght,
                    CAST(SUM(oi.qty_ordered) AS UNSIGNED) AS total_qty_sold,
                    ROUND(SUM(oi.base_row_total), 2) AS total_revenue
                FROM sales_order_item oi
                JOIN sales_order o ON oi.order_id = o.entity_id
                JOIN sales_order_address addr ON addr.parent_id = o.entity_id
                    AND addr.address_type = 'shipping'
                JOIN catalog_product_entity AS produit ON oi.sku = produit.sku
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                WHERE o.state IN ('processing', 'complete')
                  AND o.created_at >= ?
                  AND o.created_at <= ?
                  AND addr.country_id = ?
                  AND oi.row_total > 0
                GROUP BY oi.sku, oi.name, addr.country_id
            )
        ";
    }

    private function baseParams(array $filters): array
    {
        return [
            $filters['date_from'] . ' 00:00:00',
            $filters['date_to'] . ' 23:59:59',
            $filters['country'],
        ];
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function groupeClause(array $groupes): array
    {
        if (empty($groupes)) {
            return ['', []];
        }

        return [
            'WHERE groupe IN (' . implode(',', array_fill(0, count($groupes), '?')) . ')',
            array_values($groupes),
        ];
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

    /**
     * Ligne SQL => tableau typé (un tableau, pas un stdClass : compatible avec le cache
     * quand `serializable_classes => false`).
     */
    private function sanitizeRow(object $row): array
    {
        $r = (array) $row;

        $num = fn ($v) => $v === null ? null : (float) $v;

        return [
            'ean'                 => (string) $r['ean'],
            'country'             => $r['country'],
            'groupe'              => $this->utf8($r['groupe'] ?? null),
            'marque'              => $this->utf8($r['marque'] ?? null),
            'designation_produit' => $this->utf8($r['designation_produit'] ?? null),
            'prix_vente_cosma'    => $num($r['prix_vente_cosma'] ?? null),
            'cost'                => $num($r['cost'] ?? null),
            'pght'                => $num($r['pght'] ?? null),
            'total_qty_sold'      => (int) $r['total_qty_sold'],
            'total_revenue'       => $num($r['total_revenue']),
            'rank_qty'            => (int) $r['rank_qty'],
            'rank_ca'             => (int) $r['rank_ca'],
        ];
    }
}
