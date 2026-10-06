<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Logique d'accès aux produits de la boutique (base Magento).
 * Partagée par ProduitboutiqueController et ComparateurController.
 */
class Boutiqueproductservice
{
    public const CONNECTION = 'mysqlMagento';
    public const CACHE_PREFIX = 'boutique';
    public const CACHE_TTL = 3600; // 1 heure
    public const DEFAULT_PER_PAGE = 30;
    public const MAX_PER_PAGE = 100;
    public const IMAGE_SIZE = 120; // taille (px) des miniatures demandées à Magento

    // Remplace les octets UTF-8 invalides restants au lieu de faire planter json_encode
    public const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;

    /*
    |--------------------------------------------------------------------------
    | API publique
    |--------------------------------------------------------------------------
    */

    /**
     * Règles de validation communes (liste de produits).
     */
    public static function rules(): array
    {
        return [
            'search'    => ['nullable', 'string', 'max:255'],
            'name'      => ['nullable', 'string', 'max:255'],
            'marque'    => ['nullable', 'string', 'max:255'],
            'type'      => ['nullable', 'string', 'max:255'],
            'ean'       => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock'  => ['nullable', 'boolean'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ];
    }

    /**
     * Construit le tableau de filtres à partir d'une requête déjà validée.
     */
    public function filtersFrom(Request $request): array
    {
        return [
            'search'    => trim((string) $request->input('search', '')),
            'name'      => trim((string) $request->input('name', '')),
            'marque'    => trim((string) $request->input('marque', '')),
            'type'      => trim((string) $request->input('type', '')),
            'ean'       => trim((string) $request->input('ean', '')),
            'min_price' => $request->filled('min_price') ? $request->input('min_price') : null,
            'max_price' => $request->filled('max_price') ? $request->input('max_price') : null,
            'in_stock'  => $request->boolean('in_stock'),
            'skus'      => null, // liste de SKU imposée (utilisée par le comparateur)
        ];
    }

    /**
     * Une page de produits (mise en cache).
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        // Liste de SKU imposée mais vide : rien à afficher
        if (is_array($filters['skus'] ?? null) && empty($filters['skus'])) {
            return [
                'total_item'   => 0,
                'per_page'     => $perPage,
                'total_page'   => 0,
                'current_page' => 1,
                'data'         => [],
                'cached_at'    => now()->toDateTimeString(),
            ];
        }

        return Cache::remember(
            $this->cacheKey('products', $filters, $page, $perPage),
            self::CACHE_TTL,
            fn () => $this->fetchProducts($filters, $page, $perPage)
        );
    }

    /**
     * Un produit par son entity_id (mis en cache).
     */
    public function find(int $id): ?array
    {
        return Cache::remember(
            $this->versionedKey("product:{$id}"),
            self::CACHE_TTL,
            function () use ($id) {
                $rows = DB::connection(self::CONNECTION)->select(
                    $this->selectSql() . ' WHERE product_int.status >= 0 AND produit.entity_id = ?',
                    [$id]
                );

                $row = $this->pickBestRow($rows);

                return $row ? $this->sanitizeRow($row) : null;
            }
        );
    }

    /**
     * Invalide tout le cache produits via un numéro de version
     * (fonctionne avec tous les drivers, sans KEYS Redis).
     */
    public function clearCache(): void
    {
        $versionKey = self::CACHE_PREFIX . ':version';
        Cache::add($versionKey, 1);
        Cache::increment($versionKey);
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
    | Cache helpers
    |--------------------------------------------------------------------------
    */

    private function versionedKey(string $suffix): string
    {
        return sprintf('%s:v%d:%s', self::CACHE_PREFIX, $this->cacheVersion(), $suffix);
    }

    private function cacheKey(string $type, array $filters, ...$params): string
    {
        // Évite de hasher une très longue liste de SKU : on la réduit à une empreinte
        if (is_array($filters['skus'] ?? null)) {
            $skus = $filters['skus'];
            sort($skus);
            $filters['skus'] = md5(implode(',', $skus));
        }

        return $this->versionedKey(
            $type . ':' . md5(json_encode($filters)) . ':' . implode(':', $params)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération des données
    |--------------------------------------------------------------------------
    */

    private function fetchProducts(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        // Le total ne dépend pas de la page : clé de cache dédiée aux filtres
        $total = Cache::remember(
            $this->cacheKey('count', $filters),
            self::CACHE_TTL,
            fn () => $this->countProducts($where, $params)
        );

        $lastPage = (int) ceil($total / $perPage);

        if ($lastPage > 0 && $page > $lastPage) {
            $page = 1;
        }

        $offset = ($page - 1) * $perPage;

        // 1) IDs distincts de la page : on pagine des PRODUITS, pas des lignes de jointure
        $idRows = DB::connection(self::CONNECTION)->select("
            SELECT produit.entity_id AS id
            {$this->fromSql()}
            WHERE product_int.status >= 0
            {$where}
            GROUP BY produit.entity_id
            ORDER BY produit.entity_id DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$perPage, $offset]));

        $ids = array_map(fn ($r) => (int) $r->id, $idRows);

        // 2) Détail des produits de la page, dédoublonné (1 ligne par produit)
        $data = [];

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $rows = DB::connection(self::CONNECTION)->select(
                $this->selectSql() . " WHERE product_int.status >= 0 AND produit.entity_id IN ({$placeholders})",
                $ids
            );

            $byId = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;

                // On garde la 1re ligne, mais on préfère celle qui a une option (contenance)
                if (!isset($byId[$id]) || (empty($byId[$id]->option_value) && !empty($row->option_value))) {
                    $byId[$id] = $row;
                }
            }

            // Respecter l'ordre des IDs (DESC)
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $data[] = $this->sanitizeRow($byId[$id]);
                }
            }
        }

        return [
            'total_item'   => (int) $total,
            'per_page'     => $perPage,
            'total_page'   => $lastPage,
            'current_page' => $page,
            'data'         => $data,
            'cached_at'    => now()->toDateTimeString(),
        ];
    }

    private function countProducts(string $where, array $params): int
    {
        $result = DB::connection(self::CONNECTION)->selectOne("
            SELECT COUNT(DISTINCT produit.entity_id) AS nb
            {$this->fromSql()}
            WHERE product_int.status >= 0
            {$where}
        ", $params);

        return (int) ($result->nb ?? 0);
    }

    /**
     * Parmi plusieurs lignes d'un même produit, garde la 1re
     * en préférant celle qui porte une option (contenance).
     */
    private function pickBestRow(array $rows): ?object
    {
        $best = null;

        foreach ($rows as $row) {
            if ($best === null || (empty($best->option_value) && !empty($row->option_value))) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Construit la clause WHERE (paramétrée) à partir des filtres.
     *
     * @return array{0: string, 1: array}
     */
    private function buildWhere(array $filters): array
    {
        $where  = '';
        $params = [];

        // Recherche globale
        if (($filters['search'] ?? '') !== '') {
            $normalized = $this->normalizeSearch($filters['search']);
            $words      = array_values(array_filter(explode(' ', $normalized)));

            if (!empty($words)) {
                $nameConditions = [];

                foreach ($words as $word) {
                    $nameConditions[] = "LOWER(REGEXP_REPLACE(
                        CONCAT(product_char.normalized_name, ' ', COALESCE(options.attribute_value, '')),
                        '[^a-z0-9 ]', ''
                    )) LIKE ?";
                    $params[] = "%{$word}%";
                }

                $where .= ' AND ( (' . implode(' AND ', $nameConditions) . ")
                    OR LOWER(REGEXP_REPLACE(produit.sku, '[^a-z0-9 ]', '')) LIKE ? ) ";
                $params[] = "%{$normalized}%";
            }
        }

        // Filtre : nom
        if (($filters['name'] ?? '') !== '') {
            $where .= " AND LOWER(REGEXP_REPLACE(product_char.normalized_name, '[^a-z0-9 ]', '')) LIKE ? ";
            $params[] = '%' . $this->normalizeSearch($filters['name']) . '%';
        }

        // Filtre : marque
        if (($filters['marque'] ?? '') !== '') {
            $where .= " AND LOWER(REGEXP_REPLACE(
                SUBSTRING_INDEX(product_char.normalized_name, '  ', 1), '[^a-z0-9 ]', ''
            )) LIKE ? ";
            $params[] = '%' . $this->normalizeSearch($filters['marque']) . '%';
        }

        // Filtre : type
        if (($filters['type'] ?? '') !== '') {
            $where .= " AND LOWER(REGEXP_REPLACE(
                SUBSTRING_INDEX(eas.attribute_set_name, '_', -1), '[^a-z0-9 ]', ''
            )) LIKE ? ";
            $params[] = '%' . $this->normalizeSearch($filters['type']) . '%';
        }

        // Filtre : EAN / SKU
        if (($filters['ean'] ?? '') !== '') {
            $where .= " AND LOWER(REGEXP_REPLACE(produit.sku, '[^a-z0-9 ]', '')) LIKE ? ";
            $params[] = '%' . $this->normalizeSearch($filters['ean']) . '%';
        }

        // Filtre : liste de SKU imposée (ex. produits ayant un prix concurrent).
        //
        // Cette liste peut contenir des dizaines de milliers de valeurs : MySQL refuse plus de
        // 65 535 placeholders par requête préparée (erreur 1390). Les valeurs sont donc écrites
        // en littéraux dans le SQL. C'est sûr car on ne garde que des chiffres (\D retiré),
        // et on compare sans zéros de tête des deux côtés (UPC-12 / EAN-13 / GTIN-14).
        if (is_array($filters['skus'] ?? null) && !empty($filters['skus'])) {
            $digits = [];

            foreach ($filters['skus'] as $sku) {
                $sku = preg_replace('/\D/', '', (string) $sku);

                if ($sku !== '') {
                    $digits[ltrim($sku, '0') ?: '0'] = true; // clé = dédoublonnage
                }
            }

            if (empty($digits)) {
                $where .= ' AND 1 = 0 ';
            } else {
                $where .= " AND TRIM(LEADING '0' FROM produit.sku) IN ('"
                    . implode("','", array_map('strval', array_keys($digits)))
                    . "') ";
            }
        }

        // Filtre : prix minimum / maximum
        if (($filters['min_price'] ?? null) !== null) {
            $where .= ' AND product_decimal.price >= ? ';
            $params[] = (float) $filters['min_price'];
        }

        if (($filters['max_price'] ?? null) !== null) {
            $where .= ' AND product_decimal.price <= ? ';
            $params[] = (float) $filters['max_price'];
        }

        // Filtre : en stock uniquement
        if (!empty($filters['in_stock'])) {
            $where .= ' AND stock_status.stock_status = 1 ';
        }

        // Prix > 0
        $where .= ' AND product_decimal.price > 0 ';

        return [$where, $params];
    }

    /**
     * Normalise une chaîne : minuscules, sans accents, sans caractères spéciaux.
     */
    private function normalizeSearch(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
        $value = preg_replace('/[^a-z0-9 ]/', '', $value);

        return trim($value);
    }

    /**
     * Convertit une ligne SQL en tableau et force les valeurs texte en UTF-8 valide.
     * Les chaînes invalides sont supposées être en Windows-1252/Latin-1.
     *
     * On retourne un tableau (et non un stdClass) : avec `serializable_classes => false`
     * dans config/cache.php, un objet relu depuis le cache devient __PHP_Incomplete_Class.
     */
    private function sanitizeRow(object $row): array
    {
        $data = (array) $row;

        foreach ($data as $key => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $data[$key] = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            }
        }

        // Image principale : 1re image de media_gallery, sinon thumbnail, sinon swatch_image
        $firstGalleryImage = trim(explode(',', (string) ($data['media_gallery'] ?? ''))[0]);

        $data['image_url'] = $this->imageUrl($firstGalleryImage)
            ?? $this->imageUrl($data['thumbnail'] ?? null)
            ?? $this->imageUrl($data['swatch_image'] ?? null);

        return $data;
    }

    /**
     * Construit l'URL complète d'une image Magento.
     * Ex. "/a/b/photo.jpg" => "{MAGENTO_MEDIA_URL}/a/b/photo.jpg?width=120&..."
     */
    private function imageUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '' || $path === 'no_selection') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $base = config('services.magento.media_url');

        if (!$base) {
            return null;
        }

        // Miniature redimensionnée par le serveur Magento (inutile de charger du 1200x1200)
        $query = http_build_query([
            'width'      => self::IMAGE_SIZE,
            'height'     => self::IMAGE_SIZE,
            'store'      => 'base',
            'image-type' => 'image',
        ]);

        return rtrim($base, '/') . '/' . ltrim($path, '/') . '?' . $query;
    }

    /*
    |--------------------------------------------------------------------------
    | SQL
    |--------------------------------------------------------------------------
    */

    private function fromSql(): string
    {
        return "
            FROM catalog_product_entity AS produit
            LEFT JOIN catalog_product_relation AS parent_child_table ON parent_child_table.child_id = produit.entity_id
            LEFT JOIN catalog_product_super_link AS cpsl ON cpsl.product_id = produit.entity_id
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            LEFT JOIN product_text ON product_text.entity_id = produit.entity_id
            LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
            LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
            LEFT JOIN product_media ON product_media.entity_id = produit.entity_id
            LEFT JOIN product_categorie ON product_categorie.entity_id = produit.entity_id
            LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id
            LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id
            LEFT JOIN option_super_attribut AS options ON options.simple_product_id = produit.entity_id
            LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id
            LEFT JOIN catalog_product_entity AS produit_parent ON parent_child_table.parent_id = produit_parent.entity_id
            LEFT JOIN product_char AS product_parent_char ON product_parent_char.entity_id = produit_parent.entity_id
            LEFT JOIN product_text AS product_parent_text ON product_parent_text.entity_id = produit_parent.entity_id
        ";
    }

    private function selectSql(): string
    {
        return "
            SELECT
                produit.entity_id AS id,
                produit.sku AS sku,
                product_char.reference AS parkode,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) AS title,
                CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) AS parent_title,
                SUBSTRING_INDEX(product_char.name, ' - ', 1) AS vendor,
                SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) AS type,
                product_char.thumbnail AS thumbnail,
                product_char.swatch_image AS swatch_image,
                product_char.reference_us AS reference_us,
                CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) AS description,
                CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) AS short_description,
                CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) AS parent_description,
                CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) AS parent_short_description,
                CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) AS composition,
                CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) AS olfactive_families,
                CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) AS product_benefit,
                ROUND(product_decimal.price, 2) AS price,
                ROUND(product_decimal.special_price, 2) AS special_price,
                ROUND(product_decimal.cost, 2) AS cost,
                ROUND(product_decimal.pvc, 2) AS pvc,
                ROUND(product_decimal.prix_achat_ht, 2) AS prix_achat_ht,
                ROUND(product_decimal.prix_us, 2) AS prix_us,
                product_int.status AS status,
                product_int.color AS color,
                product_int.capacity AS capacity,
                product_int.product_type AS product_type,
                product_media.media_gallery AS media_gallery,
                CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) AS categorie,
                REPLACE(product_categorie.name, ' > ', ',') AS tags,
                stock_item.qty AS quantity,
                stock_status.stock_status AS quantity_status,
                options.configurable_product_id AS configurable_product_id,
                parent_child_table.parent_id AS parent_id,
                options.attribute_code AS option_name,
                options.attribute_value AS option_value
            {$this->fromSql()}
        ";
    }
}
