
@php
    $fmt = fn ($n) => number_format((float) $n, 2, ',', ' ') . ' €';

    $toneText = [
    'red'   => 'text-red-600 dark:text-red-400',
    'green' => 'text-green-600 dark:text-green-400',
    'zinc'  => 'text-zinc-700 dark:text-zinc-300',
    ];

    // Évalué ici, avant de lire $this->error (écrite pendant le calcul de la propriété computed)
    $products = $this->products;
@endphp

<div class="space-y-6" wire:init="load">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Top ventes</flux:heading>
            <flux:text class="mt-1">
                @if ($loaded)
                    {{ number_format($products->total(), 0, ',', ' ') }} produit(s)
                @else
                    <flux:skeleton class="h-4 w-24" animate="shimmer" />
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <flux:input type="date" wire:model.live="dateFrom" label="Du" class="w-40" />
            <flux:input type="date" wire:model.live="dateTo" label="Au" class="w-40" />

            <flux:select wire:model.live="sort" label="Tri" class="w-36">
                <flux:select.option value="qty">Quantité</flux:select.option>
                <flux:select.option value="ca">Chiffre d'affaires</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="groupe" label="Groupe" class="w-48">
                <flux:select.option value="">Tous</flux:select.option>
                @foreach ($this->groupes as $g)
                    <flux:select.option value="{{ $g }}">{{ $g }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="perPage" label="Lignes" class="w-24">
                <flux:select.option value="10">10</flux:select.option>
                <flux:select.option value="25">25</flux:select.option>
                <flux:select.option value="50">50</flux:select.option>
                <flux:select.option value="100">100</flux:select.option>
            </flux:select>

            <flux:dropdown align="end">
                <flux:button variant="outline" color="green" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="! $loaded">
                    Exporter Excel
                </flux:button>

                <flux:menu>
                    <flux:menu.item icon="document" wire:click="export(false)">
                        Cette page ({{ $products->count() }} lignes)
                    </flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="export(true)">
                        Tout exporter ({{ number_format($products->total(), 0, ',', ' ') }} produits)
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    {{-- Suivi de l'export Excel (poll tant que le job n'est pas terminé) --}}
    @if ($exportStatus = $this->exportStatus)
        @php $state = $exportStatus['state'] ?? 'queued'; @endphp

        <div @if (in_array($state, ['queued', 'running'])) wire:poll.2s @endif>
            @if ($state === 'done')
                <flux:callout variant="success" icon="check-circle" heading="Export terminé">
                    {{ number_format($exportStatus['done'] ?? 0, 0, ',', ' ') }} ligne(s) —
                    <button type="button" wire:click="download" class="font-medium underline">Télécharger {{ $exportStatus['name'] }}</button>
                    <x-slot name="controls">
                        <flux:button icon="x-mark" variant="ghost" size="sm" wire:click="dismissExport" />
                    </x-slot>
                </flux:callout>
            @elseif ($state === 'failed')
                <flux:callout variant="danger" icon="x-circle" heading="Échec de l'export">
                    {{ $exportStatus['message'] ?? 'Erreur inconnue' }}
                    <x-slot name="controls">
                        <flux:button icon="x-mark" variant="ghost" size="sm" wire:click="dismissExport" />
                    </x-slot>
                </flux:callout>
            @else
                <flux:callout icon="arrow-path" heading="Export en cours…">
                    @if (! empty($exportStatus['total']))
                        {{ number_format($exportStatus['done'] ?? 0, 0, ',', ' ') }} / {{ number_format($exportStatus['total'], 0, ',', ' ') }} produits traités
                    @else
                        En attente du worker de file…
                    @endif
                </flux:callout>
            @endif
        </div>
    @endif

    {{-- Onglets par pays (boutons Tailwind : flux:tabs est réservé à Flux Pro) --}}
    <div class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" role="tablist">
        @foreach ($this->countries as $code => $label)
            @php $active = $country === $code; @endphp

            <button
                type="button"
                role="tab"
                aria-selected="{{ $active ? 'true' : 'false' }}"
                wire:key="tab-{{ $code }}"
                wire:click="$set('country', '{{ $code }}')"
                class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition
                    {{ $active
                        ? 'border-zinc-800 text-zinc-900 dark:border-white dark:text-white'
                        : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-300' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($this->error)
        <flux:callout variant="danger" icon="x-circle" heading="Erreur lors du chargement des top ventes">
            {{ $this->error }}
        </flux:callout>
    @endif

    <flux:text size="sm" class="text-zinc-500">
        Écart = prix moyen des concurrents (en euros) − notre prix.
        <span class="text-green-600">Vert</span> : concurrents plus chers ·
        <span class="text-red-600">rouge</span> : concurrents moins chers ·
        gras : meilleur prix concurrent.
    </flux:text>

    <div wire:loading.class="opacity-50" wire:target="country,sort,groupe,dateFrom,dateTo,perPage,gotoPage,nextPage,previousPage,setPage">
        <flux:table :paginate="$products">
            <flux:table.columns>
                <flux:table.column align="center">Rang</flux:table.column>
                <flux:table.column>Produit</flux:table.column>
                <flux:table.column align="end">Qté vendue</flux:table.column>
                <flux:table.column align="end">CA</flux:table.column>
                <flux:table.column align="end">Notre prix</flux:table.column>
                <flux:table.column>Meilleur concurrent</flux:table.column>
                <flux:table.column>Écart marché</flux:table.column>
                @foreach ($this->visibleWebsites as $site)
                    <flux:table.column align="center" class="whitespace-nowrap">{{ $site['name'] }}</flux:table.column>
                @endforeach
            </flux:table.columns>

            <flux:table.rows>
                @if (! $loaded)
                    @foreach (range(1, min($perPage, 10)) as $i)
                        <flux:table.row :key="'skeleton-' . $i">
                            @foreach (range(1, 7 + count($this->visibleWebsites)) as $j)
                                <flux:table.cell>
                                    <div class="h-5 w-full animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @endforeach
                @else
                    @forelse ($products as $product)
                        <flux:table.row :key="$product->ean">
                            {{-- Rang --}}
                            <flux:table.cell align="center" class="whitespace-nowrap text-xs">
                                <div class="font-semibold">#{{ $product->rank_qty }} <span class="font-normal text-zinc-400">qté</span></div>
                                <div class="text-zinc-500">#{{ $product->rank_ca }} <span class="text-zinc-400">CA</span></div>
                            </flux:table.cell>

                            {{-- Produit --}}
                            <flux:table.cell class="flex items-center gap-3">
                                <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-zinc-200 bg-white dark:border-zinc-700">
                                    @if (! empty($product->image_url))
                                        <img
                                            src="{{ $product->image_url }}"
                                            alt="{{ $product->marque }}"
                                            loading="lazy"
                                            class="size-full object-contain"
                                            onerror="this.remove()"
                                        />
                                    @else
                                        <flux:icon.photo class="size-5 text-zinc-300" />
                                    @endif
                                </div>

                                <div class="min-w-0">
                                    <div class="max-w-md truncate font-medium">
                                        {{ $product->marque }}
                                        @if (($product->designation_produit ?? '') !== ($product->marque ?? ''))
                                            <span class="font-normal text-zinc-500">— {{ $product->designation_produit }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500">{{ $product->groupe }} · {{ $product->ean }}</div>
                                </div>
                            </flux:table.cell>

                            {{-- Quantité / CA --}}
                            <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                                {{ number_format($product->total_qty_sold, 0, ',', ' ') }}
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">
                                {{ $fmt($product->total_revenue) }}
                            </flux:table.cell>

                            {{-- Notre prix --}}
                            <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                                {{ $fmt($product->prix_vente_cosma) }}
                            </flux:table.cell>

                            {{-- Meilleur concurrent --}}
                            <flux:table.cell class="whitespace-nowrap">
                                @if (! empty($product->market['best']))
                                    <div class="font-medium">{{ $fmt($product->market['best']['prix_ht']) }}</div>
                                    <div class="text-xs text-zinc-500">{{ $product->market['best']['website'] }}</div>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>

                            {{-- Écart marché (moyenne concurrents − notre prix) --}}
                            <flux:table.cell class="py-0 whitespace-nowrap">
                                @php $diff = $product->market['diff'] ?? null; @endphp

                                @if ($diff === null)
                                    <span class="text-zinc-400">—</span>
                                @elseif ($diff > 0)
                                    <flux:badge size="sm" color="green">
                                        +{{ number_format($diff, 2, ',', ' ') }} € ({{ $product->market['diff_pct'] }} %)
                                    </flux:badge>
                                @elseif ($diff < 0)
                                    <flux:badge size="sm" color="red">
                                        {{ number_format($diff, 2, ',', ' ') }} € ({{ $product->market['diff_pct'] }} %)
                                    </flux:badge>
                                @else
                                    <flux:badge size="sm">Même prix</flux:badge>
                                @endif
                            </flux:table.cell>

                            {{-- Une colonne par concurrent --}}
                            @foreach ($this->visibleWebsites as $site)
                                @php
                                    $c      = $product->by_site[$site['key']] ?? null;
                                    $isBest = $c
                                    && ! empty($product->market['best'])
                                    && $product->market['best']['website'] === ($c['website']['name'] ?? null)
                                    && (float) $product->market['best']['prix_ht'] === (float) $c['prix_ht'];
                                @endphp

                                <flux:table.cell class="text-center whitespace-nowrap">
                                    @if ($c)
                                        <a
                                            href="{{ $c['url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title="{{ $c['variation'] ? trim(preg_replace('/\s+/', ' ', $c['variation'])) : 'Contenance inconnue' }}{{ $c['scraped_label'] ? ' — relevé le ' . $c['scraped_label'] : '' }}"
                                            class="inline-flex flex-col items-center text-xs hover:underline {{ $toneText[$c['tone']] }}"
                                        >
                                            <span class="{{ $isBest ? 'font-bold' : 'font-semibold' }}">{{ $fmt($c['prix_ht']) }}</span>
                                            <span class="max-w-28 truncate font-normal opacity-60">{{ trim(preg_replace('/\s+/', ' ', (string) $c['variation'])) ?: '—' }}</span>
                                        </a>
                                    @else
                                        <span class="text-zinc-300">—</span>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ 7 + count($this->visibleWebsites) }}" class="text-center text-zinc-500">
                                Aucune vente trouvée pour ces filtres.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                @endif
            </flux:table.rows>
        </flux:table>
    </div>
</div>
