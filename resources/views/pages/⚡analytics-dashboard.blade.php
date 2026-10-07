<?php

use Illuminate\Support\Facades\Http;
use Livewire\Component;

new class extends Component {
    public bool $loaded = false;
    public ?string $error = null;

    public ?array $summary = null;
    public array $meta = [];
    public array $products = [];
    public array $productsMeta = [];

    public string $country = '';
    public string $currency = 'EUR';
    public string $status = '';
    public string $search = '';
    public string $sort = 'gap_vs_median_pct';
    public int $page = 1;

    public function load(): void
    {
        $this->error = null;

        try {
            $response = Http::acceptJson()->timeout(120)
                ->get($this->base() . '/summary', array_filter([
                    'country'  => $this->country,
                    'currency' => $this->currency,
                ]))
                ->throw()
                ->json();

            $this->summary = $response['summary'];
            $this->meta    = $response['meta'];

            $this->page = 1;
            $this->fetchProducts();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->loaded = true;
    }

    public function fetchProducts(): void
    {
        $response = Http::acceptJson()->timeout(120)
            ->get($this->base() . '/products', array_filter([
                'country'  => $this->country,
                'currency' => $this->currency,
                'status'   => $this->status,
                'search'   => $this->search,
                'sort'     => $this->sort,
                'dir'      => 'desc',
                'page'     => $this->page,
                'per_page' => 15,
            ]))
            ->throw()
            ->json();

        $this->products     = $response['data'];
        $this->productsMeta = $response['meta'];
    }

    public function updatedCountry(): void  { $this->country = strtoupper($this->country); $this->load(); }
    public function updatedCurrency(): void { $this->load(); }
    public function updatedStatus(): void   { $this->page = 1; $this->fetchProducts(); }
    public function updatedSearch(): void   { $this->page = 1; $this->fetchProducts(); }
    public function updatedSort(): void     { $this->page = 1; $this->fetchProducts(); }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->fetchProducts();
        }
    }

    public function nextPage(): void
    {
        if ($this->page < ($this->productsMeta['last_page'] ?? 1)) {
            $this->page++;
            $this->fetchProducts();
        }
    }

    private function base(): string
    {
        return rtrim(config('services.analytics.url') ?: url('/api/analytics'), '/');
    }
};
?>

<div wire:init="load" class="flex h-full w-full flex-1 flex-col gap-6">
    @php
        $cur   = $meta['currency'] ?? '';
        $money = fn ($v) => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' ' . $cur;
        $fmtPct = fn ($v) => $v === null ? '—' : ($v > 0 ? '+' : '') . number_format($v, 1, ',', ' ') . ' %';
        $gapColor = fn ($v) => $v === null ? 'text-zinc-500'
            : ($v > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400');
        $indexColor = fn ($v) => $v === null ? 'text-zinc-500'
            : ($v <= 100 ? 'text-emerald-600 dark:text-emerald-400'
                : ($v <= 110 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'));
        $statuses = [
            'lowest'       => ['label' => 'Le moins cher',        'badge' => 'green', 'bar' => 'bg-emerald-500'],
            'competitive'  => ['label' => 'Compétitif',           'badge' => 'sky',   'bar' => 'bg-sky-500'],
            'above_market' => ['label' => 'Au-dessus du marché',  'badge' => 'amber', 'bar' => 'bg-amber-500'],
            'overpriced'   => ['label' => 'Trop cher',            'badge' => 'red',   'bar' => 'bg-red-500'],
            'no_data'      => ['label' => 'Sans données',         'badge' => 'zinc',  'bar' => 'bg-zinc-400'],
        ];
        $card = 'rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900';
    @endphp

    {{-- En-tête + filtres globaux --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Positionnement prix</flux:heading>
            <flux:text>Nos produits face aux prix concurrents du marché</flux:text>
        </div>
        <div class="flex items-end gap-3">
            <div class="w-24">
                <flux:input label="Pays" placeholder="Tous" maxlength="2" wire:model.live.debounce.700ms="country" />
            </div>
            <div class="w-28">
                <flux:select label="Devise" wire:model.live="currency">
                    <flux:select.option value="EUR">EUR</flux:select.option>
                    <flux:select.option value="USD">USD</flux:select.option>
                    <flux:select.option value="GBP">GBP</flux:select.option>
                </flux:select>
            </div>
            <flux:button icon="arrow-path" wire:click="load">Actualiser</flux:button>
        </div>
    </div>

    @if (! $loaded)
        {{-- Skeleton --}}
        <div class="grid gap-4 md:grid-cols-4">
            @foreach (range(1, 4) as $i)
                <div class="h-28 animate-pulse rounded-xl bg-neutral-200 dark:bg-neutral-800"></div>
            @endforeach
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div class="h-56 animate-pulse rounded-xl bg-neutral-200 dark:bg-neutral-800"></div>
            <div class="h-56 animate-pulse rounded-xl bg-neutral-200 dark:bg-neutral-800"></div>
        </div>
        <div class="h-96 animate-pulse rounded-xl bg-neutral-200 dark:bg-neutral-800"></div>

    @elseif ($error)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="Impossible de charger l'analyse">
            {{ $error }}
        </flux:callout>

    @elseif ($summary)
        <div class="flex flex-col gap-6" wire:loading.class="opacity-50"
             wire:target="load,status,search,sort,page,currency,country">

            {{-- KPIs --}}
            @php $k = $summary['kpis']; @endphp
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="{{ $card }}">
                    <flux:text size="sm">Produits analysés</flux:text>
                    <div class="mt-1 text-3xl font-semibold">{{ number_format($k['total_products'], 0, ',', ' ') }}</div>
                    <flux:text size="sm" class="mt-1">{{ $k['coverage_pct'] }} % avec données marché</flux:text>
                </div>
                <div class="{{ $card }}">
                    <flux:text size="sm">Indice de prix moyen</flux:text>
                    <div class="mt-1 text-3xl font-semibold {{ $indexColor($k['avg_price_index']) }}">
                        {{ number_format($k['avg_price_index'], 1, ',', ' ') }}
                    </div>
                    <flux:text size="sm" class="mt-1">Médian : {{ number_format($k['median_price_index'], 1, ',', ' ') }} · 100 = prix du marché</flux:text>
                </div>
                <div class="{{ $card }}">
                    <flux:text size="sm">Moins cher du marché</flux:text>
                    <div class="mt-1 text-3xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $k['cheapest_pct'] }} %</div>
                    <flux:text size="sm" class="mt-1">des produits avec données</flux:text>
                </div>
                <div class="{{ $card }}">
                    <flux:text size="sm">Opportunités de marge</flux:text>
                    <div class="mt-1 text-3xl font-semibold text-sky-600 dark:text-sky-400">{{ $k['opportunities'] }}</div>
                    <flux:text size="sm" class="mt-1">{{ $k['stale_products'] }} produits avec relevés &gt; 7 jours</flux:text>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                {{-- Répartition des statuts --}}
                <div class="{{ $card }}">
                    <flux:heading size="lg">Répartition par statut</flux:heading>
                    <div class="mt-4 flex h-4 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                        @foreach ($statuses as $key => $s)
                            @php $d = $summary['status_distribution'][$key]; @endphp
                            @if ($d['pct'] > 0)
                                <div class="{{ $s['bar'] }}" style="width: {{ $d['pct'] }}%" title="{{ $s['label'] }} : {{ $d['count'] }}"></div>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                        @foreach ($statuses as $key => $s)
                            @php $d = $summary['status_distribution'][$key]; @endphp
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span class="size-2.5 rounded-full {{ $s['bar'] }}"></span>{{ $s['label'] }}
                                </span>
                                <span class="font-medium">{{ $d['count'] }} <span class="text-zinc-500">({{ $d['pct'] }} %)</span></span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Histogramme des écarts --}}
                <div class="{{ $card }}">
                    <flux:heading size="lg">Écart vs médiane du marché</flux:heading>
                    <flux:text size="sm">Négatif = nous sommes moins chers · positif = plus chers</flux:text>
                    @php
                        $hist = $summary['gap_histogram'];
                        $maxCount = max(1, max(array_column($hist, 'count')));
                        $histColors = ['bg-emerald-500', 'bg-emerald-400', 'bg-sky-400', 'bg-amber-400', 'bg-orange-500', 'bg-red-500'];
                    @endphp
                    <div class="mt-4 flex h-40 items-end gap-2">
                        @foreach ($hist as $i => $b)
                            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1">
                                <span class="text-xs font-medium">{{ $b['count'] }}</span>
                                <div class="w-full rounded-t {{ $histColors[$i] }}" style="height: {{ max(2, $b['count'] / $maxCount * 100) }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex gap-2">
                        @foreach ($hist as $b)
                            <span class="flex-1 text-center text-xs text-zinc-500">{{ $b['range'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Tops --}}
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="{{ $card }}">
                    <flux:heading size="lg">Produits les plus chers vs marché</flux:heading>
                    <div class="mt-3 divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($summary['top_overpriced'] as $r)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium">{{ $r['name'] ?? $r['ean'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $r['vendor'] ?? '—' }} · {{ $r['ean'] }}</div>
                                </div>
                                <div class="shrink-0 text-right text-sm">
                                    <div class="font-semibold {{ $gapColor($r['gap_vs_median_pct']) }}">{{ $fmtPct($r['gap_vs_median_pct']) }}</div>
                                    <div class="text-xs text-zinc-500">{{ $money($r['price_ht']) }} → {{ $money($r['suggested_price']) }}</div>
                                </div>
                            </div>
                        @empty
                            <flux:text class="py-4">Aucun produit trop cher 🎉</flux:text>
                        @endforelse
                    </div>
                </div>

                <div class="{{ $card }}">
                    <flux:heading size="lg">Opportunités de marge</flux:heading>
                    <flux:text size="sm">Produits nettement sous le moins cher du marché</flux:text>
                    <div class="mt-3 divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($summary['top_opportunities'] as $r)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium">{{ $r['name'] ?? $r['ean'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $r['vendor'] ?? '—' }} · min marché {{ $money($r['min']) }}</div>
                                </div>
                                <div class="shrink-0 text-right text-sm">
                                    <div class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $fmtPct($r['gap_vs_min_pct']) }}</div>
                                    <div class="text-xs text-zinc-500">{{ $money($r['price_ht']) }} → {{ $money($r['suggested_price']) }}</div>
                                </div>
                            </div>
                        @empty
                            <flux:text class="py-4">Aucune opportunité détectée.</flux:text>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Par vendor / par concurrent --}}
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="{{ $card }}">
                    <flux:heading size="lg">Par marque</flux:heading>
                    <div class="mt-3 max-h-80 overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white text-left text-xs text-zinc-500 dark:bg-neutral-900">
                            <tr>
                                <th class="py-2 pr-2">Marque</th>
                                <th class="px-2 text-right">Produits</th>
                                <th class="px-2 text-right">Indice</th>
                                <th class="pl-2 text-right">Trop chers</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($summary['by_vendor'] as $v)
                                <tr>
                                    <td class="py-2 pr-2">{{ $v['vendor'] }}</td>
                                    <td class="px-2 text-right">{{ $v['products'] }}</td>
                                    <td class="px-2 text-right font-medium {{ $indexColor($v['avg_price_index']) }}">{{ number_format($v['avg_price_index'], 1, ',', ' ') }}</td>
                                    <td class="pl-2 text-right">{{ $v['overpriced_pct'] }} %</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="{{ $card }}">
                    <flux:heading size="lg">Par concurrent</flux:heading>
                    <div class="mt-3 max-h-80 overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white text-left text-xs text-zinc-500 dark:bg-neutral-900">
                            <tr>
                                <th class="py-2 pr-2">Site</th>
                                <th class="px-2 text-right">En commun</th>
                                <th class="px-2 text-right">Moins cher que nous</th>
                                <th class="pl-2 text-right">Écart moyen</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($summary['by_competitor'] as $c)
                                <tr>
                                    <td class="py-2 pr-2">
                                        {{ $c['website'] }}
                                        @if ($c['country_code'])
                                            <span class="text-xs text-zinc-500">{{ $c['country_code'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 text-right">{{ $c['common_products'] }}</td>
                                    <td class="px-2 text-right">{{ $c['cheaper_than_us_pct'] }} %</td>
                                    {{-- écart du concurrent vs nous : négatif = ils sont moins chers --}}
                                    <td class="pl-2 text-right font-medium {{ $c['avg_gap_vs_us_pct'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ $fmtPct($c['avg_gap_vs_us_pct']) }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Détail produits --}}
            <div class="{{ $card }}">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <flux:heading size="lg">Détail produits
                        <span class="text-sm font-normal text-zinc-500">({{ $productsMeta['total'] ?? 0 }})</span>
                    </flux:heading>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="w-56">
                            <flux:input icon="magnifying-glass" placeholder="Nom ou EAN…" wire:model.live.debounce.400ms="search" />
                        </div>
                        <div class="w-48">
                            <flux:select wire:model.live="status">
                                <flux:select.option value="">Tous les statuts</flux:select.option>
                                @foreach ($statuses as $key => $s)
                                    <flux:select.option value="{{ $key }}">{{ $s['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <div class="w-52">
                            <flux:select wire:model.live="sort">
                                <flux:select.option value="gap_vs_median_pct">Écart vs médiane</flux:select.option>
                                <flux:select.option value="gap_vs_min_pct">Écart vs minimum</flux:select.option>
                                <flux:select.option value="price_index">Indice de prix</flux:select.option>
                                <flux:select.option value="price_ht">Notre prix</flux:select.option>
                            </flux:select>
                        </div>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="text-left text-xs text-zinc-500">
                        <tr>
                            <th class="py-2 pr-2">Produit</th>
                            <th class="px-2 text-right">Notre prix</th>
                            <th class="px-2 text-right">Min</th>
                            <th class="px-2 text-right">Médiane</th>
                            <th class="px-2 text-right">Max</th>
                            <th class="px-2 text-right">Offres</th>
                            <th class="px-2 text-right">Écart / médiane</th>
                            <th class="px-2 text-right">Indice</th>
                            <th class="px-2 text-right">Rang</th>
                            <th class="px-2">Statut</th>
                            <th class="pl-2 text-right">Prix suggéré</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        @forelse ($products as $r)
                            @php $s = $statuses[$r['status']]; @endphp
                            <tr wire:key="p-{{ $r['ean'] }}">
                                <td class="max-w-xs py-2 pr-2">
                                    <div class="truncate font-medium">{{ $r['name'] ?? $r['ean'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $r['vendor'] ?? '—' }} · {{ $r['ean'] }}</div>
                                </td>
                                <td class="px-2 text-right font-medium">{{ $money($r['price_ht']) }}</td>
                                <td class="px-2 text-right">{{ $money($r['min']) }}</td>
                                <td class="px-2 text-right">{{ $money($r['median']) }}</td>
                                <td class="px-2 text-right">{{ $money($r['max']) }}</td>
                                <td class="px-2 text-right">{{ $r['count'] }}</td>
                                <td class="px-2 text-right font-medium {{ $gapColor($r['gap_vs_median_pct']) }}">{{ $fmtPct($r['gap_vs_median_pct']) }}</td>
                                <td class="px-2 text-right {{ $indexColor($r['price_index']) }}">{{ $r['price_index'] !== null ? number_format($r['price_index'], 1, ',', ' ') : '—' }}</td>
                                <td class="px-2 text-right">{{ $r['rank'] ? $r['rank'] . '/' . $r['rank_of'] : '—' }}</td>
                                <td class="px-2">
                                    <flux:badge size="sm" color="{{ $s['badge'] }}">{{ $s['label'] }}</flux:badge>
                                    @if ($r['stale'])
                                        <flux:badge size="sm" color="zinc" class="ml-1">périmé</flux:badge>
                                    @endif
                                </td>
                                <td class="pl-2 text-right">{{ $money($r['suggested_price']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="py-8 text-center text-zinc-500">Aucun produit pour ces filtres.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <flux:text size="sm">Page {{ $productsMeta['current_page'] ?? 1 }} / {{ $productsMeta['last_page'] ?? 1 }}</flux:text>
                    <div class="flex gap-2">
                        <flux:button size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$page <= 1">Précédent</flux:button>
                        <flux:button size="sm" icon:trailing="chevron-right" wire:click="nextPage" :disabled="$page >= ($productsMeta['last_page'] ?? 1)">Suivant</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
