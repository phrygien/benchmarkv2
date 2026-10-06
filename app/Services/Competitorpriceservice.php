<?php

namespace App\Services;

use App\Models\ScrapedProduct;

/**
 * Prix concurrents (table scrapée) pour une liste d'EAN.
 * Même logique que ComparateurController::competitorsFor(), mais réutilisable,
 * avec un filtre optionnel par pays du site concurrent.
 */
class Competitorpriceservice
{
    /**
     * @param  string[]     $eans
     * @param  string|null  $country  code pays du site concurrent (ex. "FR") ; null = tous
     * @return array<string, array{competitors: array, summary: array}>  indexé par EAN fourni
     */
    public function forEans(array $eans, ?string $country = null): array
    {
        $eans = array_values(array_unique(array_filter(array_map('strval', $eans))));

        if (empty($eans)) {
            return [];
        }

        $variants = collect($eans)
            ->flatMap(fn ($ean) => $this->eanVariants($ean))
            ->unique()
            ->values()
            ->all();

        $rowsByEan = ScrapedProduct::query()
            ->with('website:id,name,url,country_code')
            ->whereIn('ean', $variants)
            ->whereNotNull('prix_ht')
            ->where('prix_ht', '>', 0)
            ->when($country, fn ($q) => $q->whereHas(
                'website',
                fn ($w) => $w->whereRaw('UPPER(country_code) = ?', [strtoupper($country)])
            ))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ScrapedProduct $row) => $this->normalizeEan($row->ean));

        $result = [];

        foreach ($eans as $ean) {
            // Dernier relevé par site (lignes triées du plus récent au plus ancien),
            // puis tri du moins cher au plus cher
            $latestPerSite = $rowsByEan
                ->get($this->normalizeEan($ean), collect())
                ->unique('web_site_id')
                ->sortBy('prix_ht')
                ->values();

            $result[$ean] = [
                'competitors' => $latestPerSite->map(fn (ScrapedProduct $row) => [
                    'website' => [
                        'id'           => $row->website?->id,
                        'name'         => $row->website?->name,
                        'url'          => $row->website?->url,
                        'country_code' => $row->website?->country_code ? strtoupper($row->website->country_code) : null,
                    ],
                    'prix_ht'    => $row->prix_ht,
                    'currency'   => $row->currency,
                    'name'       => $row->name,
                    'vendor'     => $row->vendor,
                    'type'       => $row->type,
                    'variation'  => $row->variation,
                    'url'        => $row->url,
                    'image_url'  => $row->image_url,
                    'scraped_at' => ($row->updated_at ?? $row->created_at)?->toIso8601String(),
                ])->all(),
                // Statistiques par devise (on ne mélange pas EUR, USD, etc.)
                'summary' => [
                    'count'       => $latestPerSite->count(),
                    'by_currency' => $latestPerSite
                        ->groupBy(fn (ScrapedProduct $row) => $row->currency ?: 'N/A')
                        ->map(fn ($group) => [
                            'count' => $group->count(),
                            'min'   => round($group->min('prix_ht'), 2),
                            'max'   => round($group->max('prix_ht'), 2),
                            'avg'   => round($group->avg('prix_ht'), 2),
                        ])
                        ->all(),
                ],
            ];
        }

        return $result;
    }

    public function emptyComparison(): array
    {
        return [
            'competitors' => [],
            'summary'     => ['count' => 0, 'by_currency' => []],
        ];
    }

    /**
     * Variantes d'un EAN à chercher en base (zéros de tête : UPC-12, EAN-13, GTIN-14).
     *
     * @return string[]
     */
    private function eanVariants(string $ean): array
    {
        if (!ctype_digit($ean)) {
            return [$ean];
        }

        $trimmed = ltrim($ean, '0') ?: '0';

        return array_values(array_unique([
            $ean,
            $trimmed,
            str_pad($trimmed, 12, '0', STR_PAD_LEFT),
            str_pad($trimmed, 13, '0', STR_PAD_LEFT),
            str_pad($trimmed, 14, '0', STR_PAD_LEFT),
        ]));
    }

    private function normalizeEan(?string $ean): string
    {
        $ean = trim((string) $ean);

        return ctype_digit($ean) ? (ltrim($ean, '0') ?: '0') : $ean;
    }
}
