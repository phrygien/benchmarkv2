<?php

use Illuminate\Http\Client\RequestException;
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

    public ?string $errorDetails = null;

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

        // Premier rendu : page vide instantanée, l'API est appelée ensuite via wire:init
        if (! $this->loaded) {
            return $this->emptyPaginator($page);
        }

        $query = array_filter([
            'search'   => $this->search,
            'page'     => $page,
            'per_page' => $this->perPage,
        ], fn ($value) => $value !== '' && $value !== null);

        $this->error = null;
        $this->errorDetails = null;
        $response = [];

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                // Certificat auto-signé de Herd en local
                ->when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->get(url('/api/products'), $query)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            // Erreur HTTP (4xx / 5xx) : statut + corps de la réponse
            $this->error = $e->getMessage();
            $this->errorDetails = 'HTTP ' . $e->response->status() . "\n\n"
                . str($e->response->body())->limit(2000);
        } catch (\Throwable $e) {
            // Erreur réseau, timeout, SSL, JSON invalide...
            $this->error = $e->getMessage();
            $this->errorDetails = get_class($e) . ' — ' . basename($e->getFile()) . ':' . $e->getLine();
        }

        return new LengthAwarePaginator(
            items: collect($response['data'] ?? [])->map(fn ($row) => (object) $row),
            total: $response['meta']['total'] ?? 0,
            perPage: $this->perPage,
            currentPage: $response['meta']['current_page'] ?? $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    private function emptyPaginator(int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: $this->perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }
};
?>
