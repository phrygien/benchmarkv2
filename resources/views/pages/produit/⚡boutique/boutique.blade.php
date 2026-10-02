@php
    // Actions Livewire qui déclenchent l'affichage du skeleton
    $loadingTargets = 'search,perPage,gotoPage,nextPage,previousPage,setPage';
@endphp

<div class="space-y-6" wire:init="load">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Produits boutique</flux:heading>
            <flux:text class="mt-1">
                @if ($loaded)
                    {{ number_format($this->products->total(), 0, ',', ' ') }} produit(s)
                @else
                    <flux:skeleton class="h-4 w-24" animate="shimmer" />
                @endif
            </flux:text>
        </div>

        <div class="flex items-end gap-3">
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

    @if ($error)
        <flux:callout variant="danger" icon="x-circle" heading="Erreur lors de l'appel à l'API">
            {{ $error }}
        </flux:callout>
    @endif

    <flux:table :paginate="$this->products">
        <flux:table.columns>
            <flux:table.column>Produit</flux:table.column>
            <flux:table.column>SKU / EAN</flux:table.column>
            <flux:table.column>Type</flux:table.column>
            <flux:table.column align="end">Prix</flux:table.column>
            <flux:table.column align="end">Prix spécial</flux:table.column>
            <flux:table.column>Stock</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            {{-- Skeleton : visible au premier chargement ET pendant pagination / recherche --}}
            @foreach (range(1, $perPage) as $i)
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
                        <div class="h-5 w-28 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="h-5 w-16 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="ml-auto h-5 w-16 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="ml-auto h-5 w-16 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="h-5 w-20 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach

            {{-- Vraies lignes : masquées pendant le chargement --}}
            @if ($loaded)
                @forelse ($this->products as $product)
                    {{-- L'API peut renvoyer des doublons d'id (jointures) : on ajoute l'index à la clé --}}
                    <flux:table.row
                        :key="$product->id . '-' . $loop->index"
                        wire:loading.class="hidden"
                        wire:target="{{ $loadingTargets }}"
                    >
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
                                <div class="truncate font-medium">{{ $product->title }}</div>
                                <div class="text-xs text-zinc-500">{{ $product->vendor }}</div>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="whitespace-nowrap">{{ $product->sku }}</flux:table.cell>

                        <flux:table.cell class="py-0">
                            <flux:badge size="sm">{{ $product->type }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                            {{ number_format((float) $product->price, 2, ',', ' ') }} €
                        </flux:table.cell>

                        <flux:table.cell align="end" class="whitespace-nowrap">
                            @if (!empty($product->special_price) && $product->special_price > 0)
                                {{ number_format((float) $product->special_price, 2, ',', ' ') }} €
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="py-0">
                            @php($inStock = (float) ($product->quantity ?? 0) > 0)
                            <flux:badge size="sm" :color="$inStock ? 'green' : 'red'">
                                {{ $inStock ? (int) $product->quantity . ' en stock' : 'Rupture' }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row wire:loading.class="hidden" wire:target="{{ $loadingTargets }}">
                        <flux:table.cell colspan="6" class="text-center text-zinc-500">
                            Aucun produit trouvé.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            @endif
        </flux:table.rows>
    </flux:table>
</div>
