@php
    $int  = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $fmt  = fn ($n) => number_format((float) $n, 2, ',', ' ') . ' €';
    $pct  = fn ($part, $total) => $total > 0 ? $part / $total * 100 : 0;
    $pct1 = fn ($n) => number_format((float) $n, 1, ',', ' ') . ' %';
    $sgn  = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . number_format(abs((float) $n), 1, ',', ' ') . ' %';

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
    $as       = $a['stats'];
    $bs       = $this->dataset['stats'] ?? [];
    $products = $this->products;
    $building = $this->isBuilding;
    $stale    = $this->isStale;
    $head     = (float) ($g['median'] ?? 0);

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
                    {{ $int($g['n']) }} produit(s) matché(s) comparé(s)
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

            <flux:select wire:model.live="ourPrice" label="Notre prix" class="w-52">
                <flux:select.option value="effective">Prix pratiqué (promo incluse)</flux:select.option>
                <flux:select.option value="regular">Prix catalogue (hors promo)</flux:select.option>
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

    {{-- Fiabilité de la comparaison --}}
    <flux:card class="space-y-3">
        <flux:heading>Fiabilité de la comparaison</flux:heading>

        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="maxAge" label="Fraîcheur des relevés" class="w-48">
                <flux:select.option value="0">Tous les relevés</flux:select.option>
                <flux:select.option value="7">Moins de 7 jours</flux:select.option>
                <flux:select.option value="14">Moins de 14 jours</flux:select.option>
                <flux:select.option value="30">Moins de 30 jours</flux:select.option>
                <flux:select.option value="60">Moins de 60 jours</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="minSites" label="Concurrents minimum" class="w-44">
                <flux:select.option value="1">1 (tous)</flux:select.option>
                <flux:select.option value="2">2 ou plus</flux:select.option>
                <flux:select.option value="3">3 ou plus</flux:select.option>
                <flux:select.option value="5">5 ou plus</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="tolerance" label="Même prix si écart ≤" class="w-40">
                <flux:select.option value="0">0 %</flux:select.option>
                <flux:select.option value="0.5">0,5 %</flux:select.option>
                <flux:select.option value="1">1 %</flux:select.option>
                <flux:select.option value="2">2 %</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="outlierPct" label="« À vérifier » si écart >" class="w-44">
                <flux:select.option value="0">Désactivé</flux:select.option>
                <flux:select.option value="30">± 30 %</flux:select.option>
                <flux:select.option value="50">± 50 %</flux:select.option>
                <flux:select.option value="100">± 100 %</flux:select.option>
            </flux:select>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-2">
            <flux:switch wire:model.live="cleanOutliers" label="Écarter les relevés aberrants" />
            <flux:switch wire:model.live="excludeSuspects" label="Exclure les produits « à vérifier » des stats" />
        </div>
    </flux:card>

    {{-- Onglets par pays --}}
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

    <flux:text size="sm" class="text-zinc-500">
        Analyse limitée aux produits <strong>matchés par EAN</strong> avec au moins un concurrent.
        Écart % = notre prix ({{ $ourPrice === 'regular' ? 'catalogue, hors promo' : 'pratiqué, promo incluse' }}) ÷ prix concurrent (prix_ht) − 1,
        sur les sites du pays choisi. Aucune vérification de devise ni de variation.
        <span class="text-red-600">+ rouge</span> : nous sommes plus chers ·
        <span class="text-green-600">− vert</span> : nous sommes moins chers.
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
            Aucun produit matché n'a de prix concurrent comparable avec ces filtres
            (pays, marque, fraîcheur, nombre de concurrents).
            @if ($as['suspects'] > 0 && $excludeSuspects)
                {{ $int($as['suspects']) }} produit(s) « à vérifier » sont exclus des statistiques.
            @endif
        </flux:callout>
    @else
        <div wire:loading.class="opacity-50" wire:target="country,reference,vendor,maxAge,tolerance,ourPrice,minSites,cleanOutliers,outlierPct,excludeSuspects,position,order,search,perPage,gotoPage,nextPage,previousPage,setPage">
            <div class="space-y-6">

                {{-- Qualité des données --}}
                <flux:text size="sm" class="text-zinc-500">
                    @if (! empty($bs))
                        {{ $int($bs['kept'] ?? 0) }} produits matchés exploitables sur {{ $int($bs['received'] ?? 0) }} ·
                        {{ $int($bs['rel_dupes'] ?? 0) }} doublon(s) de relevé écarté(s) ·
                    @endif
                    {{ $int($as['removed']) }} relevé(s) aberrant(s) ignoré(s) ·
                    {{ $int($as['suspects']) }} produit(s) « à vérifier »
                    @if ($as['too_few'] > 0)
                        · {{ $int($as['too_few']) }} sans assez de concurrents
                    @endif
                </flux:text>

                {{-- Réponse directe --}}
                <flux:card class="space-y-1">
                    <flux:text size="sm">Écart médian par rapport à {{ $refLabel }} des concurrents</flux:text>

                    <div class="text-3xl font-semibold {{ $gapTone($head) }}">
                        @if ($head > 0.05)
                            Nous vendons {{ $pct1(abs($head)) }} plus cher
                        @elseif ($head < -0.05)
                            Nous vendons {{ $pct1(abs($head)) }} moins cher
                        @else
                            Nous sommes alignés sur le marché
                        @endif
                    </div>

                    <flux:text size="sm">
                        Écart moyen : {{ $sgn($g['avg']) }} ·
                        sur {{ $int($g['n']) }} produit(s)
                        (la médiane est moins sensible aux erreurs de données que la moyenne)
                    </flux:text>
                </flux:card>

                {{-- Répartition --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

                    <flux:card class="space-y-1">
                        <flux:text size="sm">Nous sommes le moins cher</flux:text>
                        <div class="text-2xl font-semibold">{{ $pct1($pct($g['best'], $g['n'])) }}</div>
                        <flux:text size="sm">{{ $int($g['best']) }} produit(s) au meilleur prix du marché</flux:text>
                    </flux:card>
                </div>

                {{-- Histogramme des écarts --}}
                <div class="space-y-2">
                    <flux:heading size="lg">Répartition des écarts</flux:heading>

                    @php $maxBin = max(1, max(array_column($a['histogram'], 'n'))); @endphp

                    <div class="space-y-1">
                        @foreach ($a['histogram'] as $bin)
                            <div class="flex items-center gap-3 text-xs" wire:key="bin-{{ $loop->index }}">
                                <div class="w-28 shrink-0 text-zinc-500">{{ $bin['label'] }}</div>
                                <div class="h-3 flex-1 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                    <div
                                        class="h-3 rounded {{ ['green' => 'bg-green-500', 'red' => 'bg-red-500', 'zinc' => 'bg-zinc-400'][$bin['tone']] }}"
                                        style="width: {{ $bin['n'] / $maxBin * 100 }}%"
                                    ></div>
                                </div>
                                <div class="w-14 text-right tabular-nums">{{ $int($bin['n']) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Détail produit par produit --}}
                <div class="space-y-3">
                    <flux:heading size="lg">Écart de chaque produit vs le marché</flux:heading>

                    <div class="flex flex-wrap items-end gap-4">
                        <flux:input
                            wire:model.live.debounce.400ms="search"
                            icon="magnifying-glass"
                            placeholder="Rechercher (nom, SKU, EAN...)"
                            clearable
                            class="w-72"
                        />

                        <flux:select wire:model.live="position" label="Afficher" class="w-56">
                            <flux:select.option value="all">Tous les produits</flux:select.option>
                            <flux:select.option value="pricier">Plus chers que le marché</flux:select.option>
                            <flux:select.option value="cheaper">Moins chers que le marché</flux:select.option>
                            <flux:select.option value="same">Même prix</flux:select.option>
                            <flux:select.option value="suspect">À vérifier (écarts extrêmes)</flux:select.option>
                        </flux:select>

                        <flux:select wire:model.live="order" label="Tri" class="w-60">
                            <flux:select.option value="gap_desc">Les plus chers d'abord</flux:select.option>
                            <flux:select.option value="gap_asc">Les moins chers d'abord</flux:select.option>
                            <flux:select.option value="gap_abs">Plus gros écart (±)</flux:select.option>
                            <flux:select.option value="best_desc">Les plus loin du meilleur prix</flux:select.option>
                        </flux:select>

                        <flux:select wire:model.live="perPage" label="Lignes" class="w-24">
                            <flux:select.option value="10">10</flux:select.option>
                            <flux:select.option value="25">25</flux:select.option>
                            <flux:select.option value="50">50</flux:select.option>
                            <flux:select.option value="100">100</flux:select.option>
                        </flux:select>

                        <flux:button variant="outline" icon="arrow-down-tray" wire:click="export">
                            Export CSV
                        </flux:button>
                    </div>

                    <flux:table :paginate="$products">
                        <flux:table.columns>
                            <flux:table.column>Produit</flux:table.column>
                            <flux:table.column align="end">Notre prix</flux:table.column>
                            <flux:table.column align="end">Prix du marché</flux:table.column>
                            <flux:table.column>Écart</flux:table.column>
                            <flux:table.column>Meilleur concurrent</flux:table.column>
                            <flux:table.column>Fourchette</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($products as $p)
                                <flux:table.row :key="'p-' . $p['sku']">
                                    <flux:table.cell>
                                        <div class="max-w-md truncate font-medium">{{ $p['title'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $p['vendor'] }} · {{ $p['sku'] }}@if ($p['ean']) · EAN {{ $p['ean'] }}@endif</div>
                                    </flux:table.cell>

                                    <flux:table.cell variant="strong" align="end" class="whitespace-nowrap">
                                        {{ $fmt($p['ours']) }}
                                        @if ($p['promo'] && $ourPrice === 'effective')
                                            <div><flux:badge size="sm" color="blue">Promo</flux:badge></div>
                                        @endif
                                    </flux:table.cell>

                                    <flux:table.cell align="end" class="whitespace-nowrap">
                                        <div class="font-medium">{{ $fmt($p['market']) }}</div>
                                        <div class="text-xs text-zinc-500">{{ $p['n'] }} site(s)</div>
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap">
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

                                        @if ($p['suspect'])
                                            <flux:badge size="sm" color="amber" class="ml-1">À vérifier</flux:badge>
                                        @endif
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap text-xs">
                                        <div class="font-medium">{{ $p['best_site'] }} · {{ $fmt($p['min']) }}</div>
                                        <div class="{{ $gapTone($p['gap_best_pct']) }}">
                                            @if ($p['gap_best_pct'] <= 0.005)
                                                nous sommes le moins cher
                                            @else
                                                nous : {{ $sgn($p['gap_best_pct']) }} vs ce prix
                                            @endif
                                        </div>
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                        {{ $fmt($p['min']) }} → {{ $fmt($p['max']) }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
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
                                    <flux:table.column align="end">Écart médian</flux:table.column>
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
                                            <flux:table.cell align="end" class="whitespace-nowrap font-medium {{ $gapTone($row['median']) }}" title="Moyenne : {{ $sgn($row['avg']) }}">
                                                {{ $sgn($row['median']) }}
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
                        « Le moins cher » = produits pour lesquels ce site propose le meilleur prix (ex æquo inclus).
                        « Moins cher que nous » = produits où ce site est strictement sous notre prix.
                        Écart médian = de combien nous sommes plus chers (+) ou moins chers (−) que ce site.
                    </flux:text>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Concurrent</flux:table.column>
                            <flux:table.column align="end">Produits comparés</flux:table.column>
                            <flux:table.column align="end">Le moins cher</flux:table.column>
                            <flux:table.column align="end">Moins cher que nous</flux:table.column>
                            <flux:table.column align="end">Écart médian vs nous</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($a['sites'] as $site)
                                <flux:table.row :key="'site-' . $site['name'] . '-' . $site['country']">
                                    <flux:table.cell variant="strong" class="whitespace-nowrap">
                                        {{ $site['name'] }}
                                        <span class="text-xs font-normal text-zinc-400">{{ $site['country'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap">{{ $int($site['compared']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-medium">
                                        {{ $int($site['cheapest']) }}
                                        <span class="text-xs font-normal text-zinc-400">({{ $pct1($pct($site['cheapest'], $site['compared'])) }})</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap">
                                        {{ $int($site['cheaper_us']) }}
                                        <span class="text-xs font-normal text-zinc-400">({{ $pct1($pct($site['cheaper_us'], $site['compared'])) }})</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-medium {{ $gapTone($site['median']) }}">
                                        {{ $sgn($site['median']) }}
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
