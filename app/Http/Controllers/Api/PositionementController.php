<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PositionnementPrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Routes (routes/api.php)
|--------------------------------------------------------------------------
|   GET  /api/positionement           positionnement prix vs marché (% moins cher / aligné / plus cher)
|   POST /api/positionement/refresh   relance le calcul en arrière-plan
|
| Paramètres de GET /api/positionement :
|   reference        min | avg | median (défaut median) : prix de marché de référence
|   tolerance        % d'écart considéré comme "aligné" (défaut 2)
|   marque, type     filtres (contient, insensible à la casse)
|   country          code pays concurrent (FR, DE, ...) : ne compare qu'aux concurrents de ce pays
|   competitor       nom / domaine du concurrent : ne compare qu'à ce concurrent
|   min_competitors  nb minimum de concurrents par produit (défaut 1)
|   group_by         marque | type | country | competitor : ajoute une ventilation
|   include_products 1 => ajoute la liste des produits (triée du plus cher au moins cher)
|   status           cheaper | aligned | expensive : filtre la liste des produits
|   page, per_page   pagination de la liste des produits (per_page max 200)
|
| Exemples :
|   GET /api/positionement
|   GET /api/positionement?reference=min&tolerance=0&group_by=marque
|   GET /api/positionement?country=FR&include_products=1&status=expensive&per_page=50
*/

class PositionementController extends Controller
{
    private const MAX_GROUPS = 100;

    public function __construct(private PositionnementPrixService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'reference'        => ['nullable', Rule::in(['min', 'avg', 'median'])],
            'tolerance'        => ['nullable', 'numeric', 'min:0', 'max:50'],
            'marque'           => ['nullable', 'string', 'max:100'],
            'type'             => ['nullable', 'string', 'max:100'],
            'country'          => ['nullable', 'string', 'size:2'],
            'competitor'       => ['nullable', 'string', 'max:100'],
            'min_competitors'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'group_by'         => ['nullable', Rule::in(['marque', 'type', 'country', 'competitor'])],
            'include_products' => ['nullable', 'boolean'],
            'status'           => ['nullable', Rule::in(['cheaper', 'aligned', 'expensive'])],
            'page'             => ['nullable', 'integer', 'min:1'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $opts = [
            'reference'       => $v['reference'] ?? 'median',
            'tolerance'       => (float) ($v['tolerance'] ?? 2),
            'marque'          => $v['marque'] ?? null,
            'type'            => $v['type'] ?? null,
            'country'         => isset($v['country']) ? strtoupper($v['country']) : null,
            'competitor'      => $v['competitor'] ?? null,
            'min_competitors' => (int) ($v['min_competitors'] ?? 1),
            'group_by'        => $v['group_by'] ?? null,
        ];

        try {
            // Lecture du cache uniquement : instantané, jamais de timeout
            $dataset = $this->service->dataset();

            if ($dataset['built_at'] === null) {
                return response()->json([
                    'status'  => 'building',
                    'message' => 'Les données de positionnement sont en cours de calcul, réessayez dans quelques instants.',
                ], 202, ['Retry-After' => 30]);
            }

            $evals  = [];
            $groups = [];

            foreach ($dataset['rows'] as $row) {
                if (! $this->matchesProduct($row, $opts)) {
                    continue;
                }

                $competitors = $this->filterCompetitors($row['competitors'], $opts);

                if ($eval = $this->evaluate($row, $competitors, $opts)) {
                    $evals[] = $eval;
                }

                if ($opts['group_by']) {
                    $this->collectGroups($groups, $row, $competitors, $opts);
                }
            }

            $data = ['summary' => $this->summarize($evals)];

            if ($opts['group_by']) {
                $data['groups'] = $this->formatGroups($groups);
            }

            if ($request->boolean('include_products')) {
                $data['products'] = $this->productsPage($evals, $v);
            }

            return response()->json([
                'data' => $data,
                'meta' => [
                    'reference'       => $opts['reference'],
                    'tolerance_pct'   => $opts['tolerance'],
                    'currency'        => 'EUR',
                    'built_at'        => date('c', $dataset['built_at']),
                    'quality'         => $dataset['stats'] ?? [],
                    'stale'           => $this->service->isStale($dataset['built_at']),
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Erreur lors du calcul du positionnement prix.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /api/positionement/refresh : relance le calcul en arrière-plan.
     */
    public function refresh(): JsonResponse
    {
        $queued = $this->service->dispatchRefresh();

        return response()->json([
            'status'  => $queued ? 'queued' : 'already_queued',
            'message' => $queued
                ? 'Recalcul lancé en arrière-plan.'
                : 'Un recalcul est déjà en file ou en cours.',
        ], 202);
    }

    /*
    |--------------------------------------------------------------------------
    | Filtres
    |--------------------------------------------------------------------------
    */

    private function matchesProduct(array $row, array $opts): bool
    {
        if ($opts['marque'] && stripos($row['vendor'], $opts['marque']) === false) {
            return false;
        }

        if ($opts['type'] && stripos($row['type'], $opts['type']) === false) {
            return false;
        }

        return true;
    }

    private function filterCompetitors(array $competitors, array $opts): array
    {
        if (! $opts['country'] && ! $opts['competitor']) {
            return $competitors;
        }

        return array_values(array_filter($competitors, function (array $c) use ($opts) {
            if ($opts['country'] && $c['country'] !== $opts['country']) {
                return false;
            }

            if ($opts['competitor']
                && stripos($c['key'], $opts['competitor']) === false
                && stripos($c['name'], $opts['competitor']) === false) {
                return false;
            }

            return true;
        }));
    }

    /*
    |--------------------------------------------------------------------------
    | Évaluation d'un produit
    |--------------------------------------------------------------------------
    */

    /**
     * Compare notre prix au prix de marché de référence.
     * gap_pct > 0 => nous sommes plus chers que le marché ; < 0 => moins chers.
     */
    private function evaluate(array $row, array $competitors, array $opts): ?array
    {
        if (count($competitors) < $opts['min_competitors']) {
            return null;
        }

        $prices = array_column($competitors, 'price');
        sort($prices);

        $count = count($prices);
        $min   = $prices[0];
        $max   = $prices[$count - 1];
        $avg   = array_sum($prices) / $count;
        $mid   = intdiv($count, 2);
        $median = $count % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;

        $ref = match ($opts['reference']) {
            'min' => $min,
            'avg' => $avg,
            default => $median,
        };

        if ($ref <= 0) {
            return null;
        }

        $ours = $row['ours'];
        $gap  = ($ours - $ref) / $ref * 100;

        $status = match (true) {
            $gap > $opts['tolerance']  => 'expensive',
            $gap < -$opts['tolerance'] => 'cheaper',
            default                    => 'aligned',
        };

        return [
            'sku'              => $row['sku'],
            'title'            => $row['title'],
            'vendor'           => $row['vendor'],
            'type'             => $row['type'],
            'our_price'        => round($ours, 2),
            'market_reference' => round($ref, 2),
            'market_min'       => round($min, 2),
            'market_avg'       => round($avg, 2),
            'market_median'    => round($median, 2),
            'market_max'       => round($max, 2),
            'competitors'      => $count,
            'gap_pct'          => round($gap, 2),
            'status'           => $status,
            'is_cheapest'      => $ours <= $min,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Agrégats
    |--------------------------------------------------------------------------
    */

    private function summarize(array $evals): array
    {
        $total = count($evals);

        $counts = ['cheaper' => 0, 'aligned' => 0, 'expensive' => 0];
        $gapSum = 0.0;
        $cheapest = 0;

        foreach ($evals as $e) {
            $counts[$e['status']]++;
            $gapSum += $e['gap_pct'];
            $cheapest += $e['is_cheapest'] ? 1 : 0;
        }

        $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 1) : 0.0;

        return [
            'total_products' => $total,
            'cheaper'   => ['count' => $counts['cheaper'],   'pct' => $pct($counts['cheaper'])],
            'aligned'   => ['count' => $counts['aligned'],   'pct' => $pct($counts['aligned'])],
            'expensive' => ['count' => $counts['expensive'], 'pct' => $pct($counts['expensive'])],
            'cheapest_of_market' => ['count' => $cheapest, 'pct' => $pct($cheapest)],
            'avg_gap_pct' => $total > 0 ? round($gapSum / $total, 2) : 0.0,
        ];
    }

    /**
     * Range chaque produit évalué dans son groupe (marque, type, pays ou concurrent).
     * Pour country / competitor, la comparaison est refaite avec les seuls concurrents du groupe.
     */
    private function collectGroups(array &$groups, array $row, array $competitors, array $opts): void
    {
        switch ($opts['group_by']) {
            case 'marque':
            case 'type':
                $label = $opts['group_by'] === 'marque' ? $row['vendor'] : $row['type'];

                if ($eval = $this->evaluate($row, $competitors, $opts)) {
                    $groups[$label][] = $eval;
                }
                break;

            case 'country':
            case 'competitor':
                $field = $opts['group_by'] === 'country' ? 'country' : 'name';
                $parts = [];

                foreach ($competitors as $c) {
                    $parts[$c[$field]][] = $c;
                }

                foreach ($parts as $label => $subset) {
                    if ($eval = $this->evaluate($row, $subset, $opts)) {
                        $groups[$label][] = $eval;
                    }
                }
                break;
        }
    }

    private function formatGroups(array $groups): array
    {
        $out = [];

        foreach ($groups as $label => $evals) {
            $out[] = ['group' => (string) $label] + $this->summarize($evals);
        }

        usort($out, fn ($a, $b) => $b['total_products'] <=> $a['total_products']);

        return array_slice($out, 0, self::MAX_GROUPS);
    }

    private function productsPage(array $evals, array $v): array
    {
        if (! empty($v['status'])) {
            $evals = array_values(array_filter($evals, fn ($e) => $e['status'] === $v['status']));
        }

        usort($evals, fn ($a, $b) => $b['gap_pct'] <=> $a['gap_pct']);

        $perPage = (int) ($v['per_page'] ?? 50);
        $page    = (int) ($v['page'] ?? 1);
        $total   = count($evals);

        return [
            'items'        => array_slice($evals, ($page - 1) * $perPage, $perPage),
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
