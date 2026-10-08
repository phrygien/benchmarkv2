@php
    // Actions Livewire qui déclenchent l'affichage du skeleton
    $loadingTargets = 'search,perPage,onlyMatched,country,gotoPage,nextPage,previousPage,setPage';

    // Nombre de lignes skeleton (inutile d'en rendre 100)
    $skeletonRows = min($perPage, 10);

    // Couleur du texte seulement (plus de cadre ni de fond)
    $toneText = [
        'red'   => 'text-red-600 dark:text-red-400',
        'green' => 'text-green-600 dark:text-green-400',
        'amber' => 'text-amber-600 line-through dark:text-amber-400',
        'zinc'  => 'text-zinc-700 dark:text-zinc-300',
    ];

    $fmt = fn ($n) => number_format((float) $n, 2, ',', ' ') . ' €';

    // On évalue les produits ICI, avant de lire $this->error :
    // l'erreur est écrite pendant le calcul de la propriété computed.
    $products = $this->products;
@endphp

<div class="space-y-6" wire:init="load">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Comparateur de prix</flux:heading>
            <flux:text class="mt-1">
                @if ($loaded)
                    {{ number_format($products->total(), 0, ',', ' ') }} produit(s)
                @else
                    <flux:skeleton class="h-4 w-24" animate="shimmer" />
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <flux:switch wire:model.live="onlyMatched" label="Avec concurrents seulement" />

            <flux:input
                wire:model.live.debounce.400ms="search"
                icon="magnifying-glass"
                placeholder="Rechercher (nom, SKU...)"
                clearable
                class="w-72"
            />

            <flux:select wire:model.live="perPage" class="w-24">
                <flux:select.option value="10">10</flux:select.option>
                <flux:select.option value="30">30</flux:select.option>
                <flux:select.option value="50">50</flux:select.option>
                <flux:select.option value="100">100</flux:select.option>
            </flux:select>
        </div>
    </div>

    {{-- Onglets par pays (boutons Tailwind : flux:tabs est réservé à Flux Pro) --}}
    @php
        // France en premier ; "Tous" en dernier
        $tabs = array_merge(
            $this->countries,
            [['code' => 'all', 'label' => 'Tous', 'count' => count($this->websites)]]
        );

        // Onglet actif : France par défaut (retombe sur "Tous" si valeur inconnue)
        $activeTab = $country === 'all' || collect($this->countries)->contains('code', $country)
            ? $country
            : 'all';
    @endphp

    <div class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" role="tablist">
        @foreach ($tabs as $tab)
            @php $active = $activeTab === $tab['code']; @endphp

            <button
                type="button"
                role="tab"
                aria-selected="{{ $active ? 'true' : 'false' }}"
                wire:key="tab-{{ $tab['code'] }}"
                wire:click="$set('country', '{{ $tab['code'] }}')"
                class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition
                    {{ $active
                        ? 'border-zinc-800 text-zinc-900 dark:border-white dark:text-white'
                        : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-300' }}"
            >
                {{ $tab['label'] }}
                <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                    {{ $tab['count'] }}
                </span>
            </button>
        @endforeach
    </div>

    {{-- $this->error (et non $error) : la variable de vue serait périmée --}}
    @if ($this->error)
        <flux:callout variant="danger" icon="x-circle" heading="Erreur lors du chargement des produits">
            {{ $this->error }}
        </flux:callout>
    @endif

    <flux:text size="sm" class="text-zinc-500">
        Écart = notre prix − meilleur prix concurrent (même contenance, en euros).
        <span class="text-red-600">Rouge</span> : concurrent moins cher ·
        <span class="text-green-600">vert</span> : concurrent plus cher ·
        <span class="text-amber-600">barré</span> : contenance différente, exclu du calcul ·
        gras : meilleur prix retenu ·
        <span class="text-amber-500">date orange</span> : relevé de plus de 7 jours.
    </flux:text>

    <flux:table :paginate="$products">
        <flux:table.columns>
            <flux:table.column>Produit</flux:table.column>
            <flux:table.column align="end">Notre prix</flux:table.column>
            <flux:table.column>Meilleur concurrent</flux:table.column>
            <flux:table.column>Écart</flux:table.column>
            @foreach ($this->visibleWebsites as $site)
                <flux:table.column align="center" class="whitespace-nowrap">
                    <div>{{ $site['name'] }}</div>
                    @if ($site['country_code'])
                        <div class="text-xs font-normal text-zinc-400">{{ $site['country_code'] }}</div>
                    @endif
                </flux:table.column>
            @endforeach
        </flux:table.columns>

        <flux:table.rows>
            {{-- Skeleton : visible au premier chargement ET pendant pagination / recherche --}}
            @foreach (range(1, $skeletonRows) as $i)
                <flux:table.row
                    :key="'skeleton-' . $i"
                    class="{{ $loaded ? 'hidden' : '' }}"
                    wire:loading.class.remove="hidden"
                    wire:target="{{ $loadingTargets }}"
                >
                    <flux:table.cell>
                        <div class="h-5 w-64 max-w-full animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="ml-auto h-5 w-16 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="h-5 w-28 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="h-5 w-20 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    @foreach ($this->visibleWebsites as $site)
                        <flux:table.cell>
                            <div class="mx-auto h-8 w-20 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        </flux:table.cell>
                    @endforeach
                </flux:table.row>
            @endforeach

            {{-- Vraies lignes : masquées pendant le chargement --}}
            @if ($loaded)
                @forelse ($products as $product)
                    <flux:table.row
                        :key="$product->id"
                        wire:loading.class="hidden"
                        wire:target="{{ $loadingTargets }}"
                    >
                        {{-- Produit --}}
                        <flux:table.cell class="flex items-center gap-3">
                            <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-zinc-200 bg-white dark:border-zinc-700">
                                @if (!empty($product->image_url))
                                    <img
                                        src="{{ $product->image_url }}"
                                        alt="{{ $product->title }}"
                                        loading="lazy"
                                        class="size-full object-contain"
                                        onerror="this.remove()"
                                    />
                                @else
                                    <flux:icon.photo class="size-5 text-zinc-300" />
                                @endif
                            </div>

                            <div class="min-w-0">
                                <div class="max-w-md truncate font-medium">{{ $product->title }}</div>
                                <div class="text-xs text-zinc-500">{{ $product->vendor }} · {{ $product->sku }}</div>
                            </div>
                        </flux:table.cell>

                        {{-- Notre prix --}}
                        <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                            {{ $fmt($product->price) }}
                        </flux:table.cell>

                        {{-- Meilleur concurrent --}}
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($product->best)
                                <div class="font-medium">{{ $fmt($product->best['prix_ht']) }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $product->best['website']['name'] }}
                                    @if (!empty($product->best['website']['country_code']))
                                        · {{ strtoupper($product->best['website']['country_code']) }}
                                    @endif
                                    @if (!empty($product->best['scraped_label']))
                                        ·
                                        <span class="{{ $product->best['is_stale'] ? 'text-amber-500' : '' }}">
                                            {{ $product->best['scraped_label'] }}
                                        </span>
                                    @endif
                                </div>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>

                        {{-- Écart --}}
                        <flux:table.cell class="py-0 whitespace-nowrap">
                            @if ($product->gap === null)
                                <span class="text-zinc-400">—</span>
                            @elseif ($product->gap > 0)
                                <flux:badge size="sm" color="red">
                                    +{{ number_format($product->gap, 2, ',', ' ') }} € ({{ $product->gap_pct }} %)
                                </flux:badge>
                            @elseif ($product->gap < 0)
                                <flux:badge size="sm" color="green">
                                    {{ number_format($product->gap, 2, ',', ' ') }} € ({{ $product->gap_pct }} %)
                                </flux:badge>
                            @else
                                <flux:badge size="sm">Même prix</flux:badge>
                            @endif
                        </flux:table.cell>

                        {{-- Une colonne par concurrent --}}
                        @foreach ($this->visibleWebsites as $site)
                            @php
                                $c      = $product->by_site[$site['key']] ?? null;
                                $isBest = $c && $product->best_key === $site['key'];
                            @endphp

                            <flux:table.cell class="text-center whitespace-nowrap">
                                @if ($c)
                                    <a
                                        href="{{ $c['url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title="{{ $c['variation'] ?: 'Contenance inconnue' }}{{ $c['volume_mismatch'] ? ' — contenance différente, non comparable' : '' }}{{ $c['scraped_full'] ? ' — relevé le ' . $c['scraped_full'] : '' }}"
                                        class="inline-flex flex-col items-center text-xs hover:underline {{ $toneText[$c['tone']] }}"
                                    >
                                        <span class="{{ $isBest ? 'font-bold' : 'font-semibold' }}">{{ $fmt($c['prix_ht']) }}</span>
                                        @if ($c['scraped_label'])
                                            <span class="text-[11px] font-normal {{ $c['is_stale'] ? 'text-amber-500' : 'text-zinc-500 dark:text-zinc-400' }}">
                                                Dernière MAJ : {{ $c['scraped_label'] }}
                                            </span>
                                        @else
                                            <span class="text-[11px] font-normal text-zinc-400">Dernière MAJ : —</span>
                                        @endif
                                        <span class="max-w-28 truncate font-normal opacity-60">{{ $c['variation'] ?: '—' }}</span>
                                    </a>
                                @else
                                    <span class="text-zinc-300">—</span>
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @empty
                    <flux:table.row wire:loading.class="hidden" wire:target="{{ $loadingTargets }}">
                        <flux:table.cell colspan="{{ 4 + count($this->visibleWebsites) }}" class="text-center text-zinc-500">
                            Aucun produit trouvé.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            @endif
        </flux:table.rows>
    </flux:table>

    <flux:text size="sm" class="text-zinc-500">
        Écart = notre prix − meilleur prix concurrent (même contenance, en euros).
        <span class="text-red-600">Rouge</span> : concurrent moins cher ·
        <span class="text-green-600">vert</span> : concurrent plus cher ·
        <span class="text-amber-600">barré</span> : contenance différente, exclu du calcul ·
        gras : meilleur prix retenu ·
        <span class="text-amber-500">date orange</span> : relevé de plus de 7 jours.
    </flux:text>
</div>
