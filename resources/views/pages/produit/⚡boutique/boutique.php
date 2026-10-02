<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public int $perPage = 100;

    public ?string $error = null;

    // false tant que la page n'a pas fini son premier affichage
    public bool $loaded = false;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $page = $this->getPage();
        $this->error = null;

        // Premier rendu : page vide instantanée, l'API est appelée ensuite via wire:init
        if (! $this->loaded) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $this->perPage,
                currentPage: $page,
                options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
            );
        }

        $query = array_filter([
            'search'   => $this->search,
            'page'     => $page,
            'per_page' => $this->perPage,
        ], fn ($value) => $value !== '' && $value !== null);

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                // Certificat auto-signé de Herd en local
                ->when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->get(url('/api/products'), $query)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $response = [];
        }

        return new LengthAwarePaginator(
            items: collect($response['data'] ?? [])->map(fn ($row) => (object) $row),
            total: $response['meta']['total'] ?? 0,
            perPage: $this->perPage,
            currentPage: $response['meta']['current_page'] ?? $page,
            options: [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }
};
?>
