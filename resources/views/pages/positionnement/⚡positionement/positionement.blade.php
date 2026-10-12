@php
    $int  = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $fmt  = fn ($n) => number_format((float) $n, 2, ',', ' ') . ' €';
    $pct  = fn ($part, $total) => $total > 0 ? $part / $total * 100 : 0;
    $pct1 = fn ($n) => number_format((float) $n, 1, ',', ' ') . ' %';
    $sgn  = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . number_format(abs((float) $n), 1, ',', ' ') . ' %';

    // Couleur de l'écart : rouge si nous sommes plus chers, vert si moins chers
    $gapTone = fn ($n) => $n > 0.5
        ? 'text-red-600 dark:text-red-400'
        : ($n < -0.5 ? 'text-green-600 dark:text-green-400' : 'text-zinc-600 dark:text-zinc-300');

    $refLabel = [
        'median' => 'le prix médian',
        'avg'    => 'le prix moyen',
        'best'   => 'le meilleur prix',
    ][$reference] ?? 'le prix médian';

    // Évalué ici, avant de lire $this->error (écrite pendant le calcul de la propriété computed)
    $a        = $this->analysis;
    $g        = $a['global'];
    $products = $this->products;
    $building = $this->isBuilding;
    $stale    = $this->isStale;

    // Rafraîchissement automatique tant qu'un calcul est en cours
    $polling  = $building || $refreshing;
@endphp

<div class="space-y-6" @if ($polling) wire:poll.4s="checkRefresh" @endif>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Positionnement prix</flux:heading>
            <flux:text class="mt-1">
                @if ($building)
                    Calcul initial en cours…
                @else
                    {{ $int($g['n']) }} produit(s) comparé(s)
                    @if ($this->builtAt)
                        · données calculées le {{ $this->builtAt }}
                    @endif
                    @if ($stale && ! $refreshing)
                        <flux:badge size="sm" color="amber" class="ml-2">Mise à jour en arrière-plan</flux:badge>
                    @endif
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="reference" label="Référence marché" class="w-56">
                @foreach ($this->references as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="vendor" label="Marque" class="w-48">
                <flux:select.option value="">Toutes</flux:select.option>
                @foreach ($this->vendors as $v)
                    <flux:select.option value="{{ $v }}">{{ $v }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="maxAge" label="Fraîcheur des relevés" class="w-48">
                <flux:select.option value="0">Tous les relevés</flux:select.option>
                <flux:select.option value="7">Moins de 7 jours</flux:select.option>
                <flux:select.option value="14">Moins de 14 jours</flux:select.option>
                <flux:select.option value="30">Moins de 30 jours</flux:select.option>
                <flux:select.option value="60">Moins de 60 jours</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="tolerance" label="Même prix si écart ≤" class="w-40">
                <flux:select.option value="0">0 %</flux:select.option>
                <flux:select.option value="0.5">0,5 %</flux:select.option>
                <flux:select.option value="1">1 %</flux:select.option>
                <flux:select.option value="2">2 %</flux:select.option>
            </flux:select>

            <flux:button
                variant="outline"
                icon="arrow-path"
                wire:click="refresh"
                wire:loading.attr="disabled"
                wire:target="refresh"
                :disabled="$polling"
            >
                @if ($refreshing || $building)
                    Calcul en cours…
                @else
                    Recalculer
                @endif
            </flux:button>
        </div>
    </div>

    {{-- Onglets par pays (pays des sites concurrents) --}}
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
        <flux:callout variant="danger" icon="x-circle" heading="Erreur lors du calcul du positionnement prix">
            {{ $this->error }}
        </flux:callout>
    @endif

    @if ($refreshing && ! $building)
        <flux:callout icon="arrow-path" heading="Recalcul en cours">
            Les chiffres ci-dessous datent du {{ $this->builtAt }}. Ils seront mis à jour automatiquement
            dès la fin du calcul (quelques minutes selon la taille du catalogue).
        </flux:callout>
    @endif

    <flux:callout variant="warning" icon="exclamation-triangle" heading="À vérifier avant toute décision">
        Cette analyse repose sur les prix relevés sur les sites concurrents (scraping) enregistrés dans notre base.
        Ces données peuvent contenir des erreurs : produit ou contenance différents, EAN mal associé, relevé ancien.
        Vérifiez les écarts importants (surtout au-delà de ± 50 %) avant de modifier un prix.
    </flux:callout>

    <flux:text size="sm" class="text-zinc-500">
        Écart % = notre prix (champ « price », sans promo ni taxe) ÷ prix du marché − 1, en euros et à contenance identique, sur les sites du pays choisi.
        <span class="text-red-600">+ rouge</span> : nous vendons plus cher que le marché ·
        <span class="text-green-600">− vert</span> : nous vendons moins cher que le marché.
    </flux:text>

    @if ($building)
        <flux:callout icon="clock" heading="Premier calcul en cours">
            Le jeu de données est en cours de construction en arrière-plan : le catalogue complet est comparé
            aux prix concurrents, ce qui peut prendre plusieurs minutes. Cette page se met à jour toute seule.
            <div class="mt-2 text-xs text-zinc-500">
                Si rien n'évolue, vérifiez que le worker tourne (<code>php artisan queue:work</code>)
                ou lancez <code>php artisan positionnement:build --force</code>.
            </div>
        </flux:callout>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach (range(1, 3) as $i)
                <div class="h-24 animate-pulse rounded-xl bg-zinc-200 dark:bg-zinc-700"></div>
            @endforeach
        </div>
        <div class="h-64 animate-pulse rounded-xl bg-zinc-200 dark:bg-zinc-700"></div>
    @elseif ($g['n'] === 0)
        <flux:callout icon="information-circle" heading="Aucune donnée pour ces filtres">
            Aucun produit n'a de prix concurrent comparable avec ces filtres (pays, marque, fraîcheur).
        </flux:callout>
    @else
        <div wire:loading.class="opacity-50" wire:target="country,reference,vendor,maxAge,tolerance,position,order,search,perPage,gotoPage,nextPage,previousPage,setPage">
            <div class="space-y-6">

                {{-- Réponse directe : combien plus cher / moins cher que le marché --}}
                <flux:card class="space-y-1">
                    <flux:text size="sm">En moyenne, par rapport à {{ $refLabel }} des concurrents</flux:text>

                    <div class="text-3xl font-semibold {{ $gapTone($g['avg']) }}">
                        @if ($g['avg'] > 0.05)
                            Nous vendons {{ $pct1(abs($g['avg'])) }} plus cher
                        @elseif ($g['avg'] < -0.05)
                            Nous vendons {{ $pct1(abs($g['avg'])) }} moins cher
                        @else
                            Nous sommes alignés sur le marché
                        @endif
                    </div>

                    <flux:text size="sm">
                        Médiane des écarts : {{ $sgn($g['median'] ?? 0) }} ·
                        sur {{ $int($g['n']) }} produit(s)
                    </flux:text>
                </flux:card>

                {{-- Répartition --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:card class="space-y-1">
                        <flux:text size="sm">Nous vendons moins cher</flux:text>
                        <div class="text-2xl font-semibold text-green-600 dark:text-green-400">{{ $pct1($pct($g['cheaper'], $g['n'])) }}</div>
                        <flux:text size="sm">{{ $int($g['cheaper']) }} produit(s)</flux:text>
                    </flux:card>

                    <flux:card class="space-y-1">
                        <flux:text size="sm">Même prix (± {{ number_format($tolerance, 1, ',', ' ') }} %)</flux:text>
                        <div class="text-2xl font-semibold">{{ $pct1($pct($g['same'], $g['n'])) }}</div>
                        <flux:text size="sm">{{ $int($g['same']) }} produit(s)</flux:text>
                    </flux:card>

                    <flux:card class="space-y-1">
                        <flux:text size="sm">Nous vendons plus cher</flux:text>
                        <div class="text-2xl font-semibold text-red-600 dark:text-red-400">{{ $pct1($pct($g['pricier'], $g['n'])) }}</div>
                        <flux:text size="sm">{{ $int($g['pricier']) }} produit(s)</flux:text>
                    </flux:card>
                </div>

                {{-- Détail produit par produit --}}
                <div class="space-y-3">
                    <flux:heading size="lg">Écart de chaque produit vs le marché</flux:heading>

                    <div class="flex flex-wrap items-end gap-4">
                        <flux:input
                            wire:model.live.debounce.400ms="search"
                            icon="magnifying-glass"
                            placeholder="Rechercher (nom, SKU...)"
                            clearable
                            class="w-72"
                        />

                        <flux:select wire:model.live="position" label="Afficher" class="w-48">
                            <flux:select.option value="all">Tous les produits</flux:select.option>
                            <flux:select.option value="pricier">Plus chers que le marché</flux:select.option>
                            <flux:select.option value="cheaper">Moins chers que le marché</flux:select.option>
                            <flux:select.option value="same">Même prix</flux:select.option>
                        </flux:select>

                        <flux:select wire:model.live="order" label="Tri" class="w-56">
                            <flux:select.option value="gap_desc">Les plus chers d'abord</flux:select.option>
                            <flux:select.option value="gap_asc">Les moins chers d'abord</flux:select.option>
                            <flux:select.option value="gap_abs">Plus gros écart (±)</flux:select.option>
                        </flux:select>

                        <flux:select wire:model.live="perPage" label="Lignes" class="w-24">
                            <flux:select.option value="10">10</flux:select.option>
                            <flux:select.option value="25">25</flux:select.option>
                            <flux:select.option value="50">50</flux:select.option>
                            <flux:select.option value="100">100</flux:select.option>
                        </flux:select>
                    </div>

                    <flux:table :paginate="$products">
                        <flux:table.columns>
                            <flux:table.column>Produit</flux:table.column>
                            <flux:table.column align="end">Notre prix</flux:table.column>
                            <flux:table.column align="end">Prix du marché</flux:table.column>
                            <flux:table.column>Écart</flux:table.column>
                            <flux:table.column>Fourchette concurrents</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($products as $p)
                                <flux:table.row :key="'p-' . $p['sku']">
                                    <flux:table.cell>
                                        <div class="max-w-md truncate font-medium">{{ $p['title'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $p['vendor'] }} · {{ $p['sku'] }}</div>
                                    </flux:table.cell>

                                    <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                                        {{ $fmt($p['ours']) }}
                                    </flux:table.cell>

                                    <flux:table.cell align="end" class="whitespace-nowrap">
                                        <div class="font-medium">{{ $fmt($p['market']) }}</div>
                                        <div class="text-xs text-zinc-500">{{ $p['n'] }} site(s)</div>
                                    </flux:table.cell>

                                    <flux:table.cell class="py-0 whitespace-nowrap">
                                        @if ($p['position'] === 'pricier')
                                            <flux:badge size="sm" color="red">
                                                {{ $sgn($p['gap_pct']) }} plus cher
                                                ({{ $p['gap_eur'] > 0 ? '+' : '' }}{{ number_format($p['gap_eur'], 2, ',', ' ') }} €)
                                            </flux:badge>
                                        @elseif ($p['position'] === 'cheaper')
                                            <flux:badge size="sm" color="green">
                                                {{ $sgn($p['gap_pct']) }} moins cher
                                                ({{ number_format($p['gap_eur'], 2, ',', ' ') }} €)
                                            </flux:badge>
                                        @else
                                            <flux:badge size="sm">Même prix ({{ $sgn($p['gap_pct']) }})</flux:badge>
                                        @endif
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                        {{ $fmt($p['min']) }} → {{ $fmt($p['max']) }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        Aucun produit pour ces filtres.
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                {{-- Par marque / par type --}}
                @foreach ([
                    ['title' => 'Par marque', 'label' => 'Marque', 'rows' => $a['vendors'], 'scroll' => true],
                    ['title' => 'Par type de produit', 'label' => 'Type', 'rows' => $a['types'], 'scroll' => false],
                ] as $group)
                    <div class="space-y-2">
                        <flux:heading size="lg">{{ $group['title'] }}</flux:heading>

                        <div class="{{ $group['scroll'] ? 'max-h-[28rem] overflow-y-auto' : '' }}">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ $group['label'] }}</flux:table.column>
                                    <flux:table.column align="end">Produits</flux:table.column>
                                    <flux:table.column>Répartition</flux:table.column>
                                    <flux:table.column align="end">Moins chers</flux:table.column>
                                    <flux:table.column align="end">Plus chers</flux:table.column>
                                    <flux:table.column align="end">Écart moyen</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($group['rows'] as $row)
                                        <flux:table.row :key="$group['title'] . '-' . $row['name']">
                                            <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row['name'] }}</flux:table.cell>
                                            <flux:table.cell align="end" class="whitespace-nowrap">{{ $int($row['n']) }}</flux:table.cell>
                                            <flux:table.cell>
                                                <div class="flex h-2 w-40 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" title="{{ $int($row['cheaper']) }} moins chers · {{ $int($row['same']) }} même prix · {{ $int($row['pricier']) }} plus chers">
                                                    <div class="bg-green-500" style="width: {{ $pct($row['cheaper'], $row['n']) }}%"></div>
                                                    <div class="bg-zinc-400" style="width: {{ $pct($row['same'], $row['n']) }}%"></div>
                                                    <div class="bg-red-500" style="width: {{ $pct($row['pricier'], $row['n']) }}%"></div>
                                                </div>
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="whitespace-nowrap text-green-600 dark:text-green-400">
                                                {{ $pct1($pct($row['cheaper'], $row['n'])) }}
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="whitespace-nowrap text-red-600 dark:text-red-400">
                                                {{ $pct1($pct($row['pricier'], $row['n'])) }}
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="whitespace-nowrap font-medium {{ $gapTone($row['avg']) }}">
                                                {{ $sgn($row['avg']) }}
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                @endforeach

                {{-- Concurrents --}}
                <div class="space-y-2">
                    <flux:heading size="lg">Concurrents</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">
                        « Le moins cher » = nombre de produits pour lesquels ce site propose le meilleur prix (ex æquo inclus).
                        Écart moyen = de combien nous sommes plus chers (+) ou moins chers (−) que ce site.
                    </flux:text>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Concurrent</flux:table.column>
                            <flux:table.column align="end">Produits comparés</flux:table.column>
                            <flux:table.column align="end">Le moins cher</flux:table.column>
                            <flux:table.column align="end">% des produits</flux:table.column>
                            <flux:table.column align="end">Écart moyen vs nous</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($a['sites'] as $site)
                                <flux:table.row :key="'site-' . $site['name'] . '-' . $site['country']">
                                    <flux:table.cell variant="strong" class="whitespace-nowrap">
                                        {{ $site['name'] }}
                                        <span class="text-xs font-normal text-zinc-400">{{ $site['country'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap">{{ $int($site['compared']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-medium">{{ $int($site['cheapest']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap">{{ $pct1($pct($site['cheapest'], $site['compared'])) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-medium {{ $gapTone($site['avg']) }}">
                                        {{ $sgn($site['avg']) }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>
    @endif
</div>
