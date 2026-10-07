<?php

use App\Services\Boutiqueproductservice;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
    public int $perPage = 30;

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
        $page    = $this->getPage();
        $perPage = max(1, min($this->perPage, Boutiqueproductservice::MAX_PER_PAGE));

        // Premier rendu : page vide instantanée, les données arrivent via wire:init
        if (! $this->loaded) {
            return $this->makePaginator([], 0, $perPage, $page);
        }

        $this->error = null;
        $this->errorDetails = null;

        // Même structure que Boutiqueproductservice::filtersFrom()
        // (donc même clé de cache que l'API)
        $filters = [
            'search'    => trim($this->search),
            'name'      => '',
            'marque'    => '',
            'type'      => '',
            'ean'       => '',
            'min_price' => null,
            'max_price' => null,
            'in_stock'  => false,
            'skus'      => null,
        ];

        try {
            $payload = app(Boutiqueproductservice::class)->paginate($filters, $page, $perPage);

            return $this->makePaginator(
                collect($payload['data'])->map(fn ($row) => (object) $row),
                (int) $payload['total_item'],
                $perPage,
                (int) $payload['current_page'],
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->errorDetails = get_class($e) . ' — ' . basename($e->getFile()) . ':' . $e->getLine();

            return $this->makePaginator([], 0, $perPage, $page);
        }
    }

    private function makePaginator($items, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }
};
?>
