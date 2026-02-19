<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

class ApiCollection extends ResourceCollection
{
    protected ?string $sortBy = null;
    protected ?string $sortOrder = null;
    protected int|string|null $perPageOverride = null;
    protected ?string $resourceClass = null;

    public static $wrap = null;

    public static function makeFromQuery(
        $queryOrCollection,
        int|string $perPage = 20,
        ?string $sortBy = null,
        ?string $sortOrder = null
    ): self {
        $sortBy ??= 'created_at';
        $sortOrder = strtolower($sortOrder ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($queryOrCollection instanceof Collection) {
            $collection = $queryOrCollection;

            // Apply sorting on Collection if needed
            if ($sortBy) {
                $collection = $collection->sortBy(
                    $sortBy,
                    SORT_REGULAR,
                    $sortOrder === 'desc'
                )->values();
            }
        } elseif ($queryOrCollection instanceof Builder || $queryOrCollection instanceof Relation) {
            // Apply sorting on query builder
            if ($sortBy) {
                $queryOrCollection = $queryOrCollection->orderBy($sortBy, $sortOrder);
            }

            $collection = $perPage == 'All'
                ? $queryOrCollection->get()
                : $queryOrCollection->paginate((int)$perPage);
        } else {
            throw new \InvalidArgumentException(
                'ApiCollection expects a Collection, Eloquent Builder, or Relation.'
            );
        }

        return (new self($collection))
            ->sort($sortBy, $sortOrder)
            ->perPage($perPage);
    }


    public function sort(string $by, string $order): self
    {
        $this->sortBy = $by;
        $this->sortOrder = $order;
        return $this;
    }

    public function perPage(int|string $perPage): self
    {
        $this->perPageOverride = $perPage;
        return $this;
    }

    public function useResource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toResponse($request)
    {
        return JsonResource::toResponse($request);
    }

    /**
     * Convert to array - optimized to avoid double iteration
     */
    public function toArray($request): array
    {
        $isPaginated = $this->resource instanceof AbstractPaginator;

        $items = $isPaginated
            ? $this->resource->items()
            : $this->collection;

        if ($this->resourceClass) {
            $resourceClass = $this->resourceClass;
            $items = collect($items)->map(function ($item) use ($resourceClass, $request) {
                return (new $resourceClass($item))->resolve($request);
            });
        } else {
            $items = collect($items);
        }

        if ($isPaginated) {
            return [
                'success' => true,
                'data' => $items->all(),
                'meta' => [
                    'current_page' => $this->resource->currentPage(),
                    'last_page' => $this->resource->lastPage(),
                    'per_page' => $this->resource->perPage(),
                    'total' => $this->resource->total(),
                    'sort_by' => $this->sortBy,
                    'sort_order' => $this->sortOrder,
                ]

            ];
        }

        // Non-paginated fallback
        $total = $items->count();

        return [
            'success' => true,
            'data' => $items->all(),
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => is_numeric($this->perPageOverride) ? $this->perPageOverride : $total,
                'total' => $total,
                'sort_by' => $this->sortBy,
                'sort_order' => $this->sortOrder,
            ]
        ];
    }
}
