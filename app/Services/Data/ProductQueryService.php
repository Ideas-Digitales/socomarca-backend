<?php

namespace App\Services\Data;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductQueryService
{
    private array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function getPaginatedProducts(int $perPage = 20)
    {
        return $this->buildQuery()
            ->paginate($perPage);
    }

    public function getPaginatedProductsWithAllowedPrices(int $perPage = 20)
    {
        return $this->buildQuery()
            ->whereHas('prices', function (Builder $q) {
                $user = Auth::user();
                $priceLists = json_decode($user->prices_lists);

                $q->when(
                    ! config('random.show_product_zero_price'),
                    fn ($qq) => $qq->where('price', '>', 0)
                );
                $q->where('is_active', true);
                $q->where('stock', '>', 0);
                $q->whereIn('price_list_id', $priceLists);
            })
            ->paginate($perPage);
    }

    public function getMatchingCategories(): array
    {
        $filtersWithoutSort = array_diff_key($this->filters, array_flip(['sort', 'sort_direction']));

        $service = new self($filtersWithoutSort);

        $matchingProducts = $service->buildQuery()
            ->where('status', true)
            ->select('supercategory_id', 'category_id', 'subcategory_id')
            ->get();

        if ($matchingProducts->isEmpty()) {
            return [
                'supercategories' => [],
                'categories' => [],
                'subcategories' => [],
            ];
        }

        $supercategoryIds = $matchingProducts->pluck('supercategory_id')->filter()->unique()->values();
        $categoryIds = $matchingProducts->pluck('category_id')->filter()->unique()->values();
        $subcategoryIds = $matchingProducts->pluck('subcategory_id')->filter()->unique()->values();

        return [
            'supercategories' => $this->fetchCategories($supercategoryIds, 1),
            'categories' => $this->fetchCategories($categoryIds, 2),
            'subcategories' => $this->fetchCategories($subcategoryIds, 3),
        ];
    }

    private function buildQuery(): Builder
    {
        $query = Product::query()->where('status', true);

        $this->applyPriceFilter($query);
        $this->applySupercategoryFilter($query);
        $this->applyCategoryFilter($query);
        $this->applySubcategoryFilter($query);
        $this->applyBrandFilter($query);
        $this->applySkuFilter($query);
        $this->applyNameFilter($query);
        $this->applyFavoriteFilter($query);
        $this->applySorting($query);

        return $query;
    }

    private function applyPriceFilter(Builder $query): void
    {
        if (! isset($this->filters['price'])) {
            return;
        }

        $priceFilter = $this->filters['price'];

        $query->whereHas('prices', function (Builder $q) use ($priceFilter) {
            $user = Auth::user();
            $priceLists = json_decode($user->prices_lists);

            $q->whereIn('price_list_id', $priceLists);

            if (isset($priceFilter['min'])) {
                $q->where('price', '>=', $priceFilter['min']);
            }

            if (isset($priceFilter['max'])) {
                $q->where('price', '<=', $priceFilter['max']);
            }

            $q->where('is_active', true);

            if (isset($priceFilter['unit'])) {
                $q->where('unit', $priceFilter['unit']);
            }
        });
    }

    private function applySupercategoryFilter(Builder $query): void
    {
        if (! isset($this->filters['supercategory_id'])) {
            return;
        }

        $ids = is_array($this->filters['supercategory_id'])
            ? $this->filters['supercategory_id']
            : [$this->filters['supercategory_id']];

        $query->whereIn('supercategory_id', $ids);
    }

    private function applyCategoryFilter(Builder $query): void
    {
        if (! isset($this->filters['category_id'])) {
            return;
        }

        $ids = is_array($this->filters['category_id'])
            ? $this->filters['category_id']
            : [$this->filters['category_id']];

        $query->whereIn('category_id', $ids);
    }

    private function applySubcategoryFilter(Builder $query): void
    {
        if (! isset($this->filters['subcategory_id'])) {
            return;
        }

        $ids = is_array($this->filters['subcategory_id'])
            ? $this->filters['subcategory_id']
            : [$this->filters['subcategory_id']];

        $query->whereIn('subcategory_id', $ids);
    }

    private function applyBrandFilter(Builder $query): void
    {
        if (! isset($this->filters['brand_id'])) {
            return;
        }

        $query->whereIn('brand_id', $this->filters['brand_id']);
    }

    private function applySkuFilter(Builder $query): void
    {
        if (! isset($this->filters['sku'])) {
            return;
        }

        $query->where('sku', $this->filters['sku']);
    }

    private function applyNameFilter(Builder $query): void
    {
        if (! isset($this->filters['name'])) {
            return;
        }

        $searchTerm = $this->filters['name'];

        $query->where(function (Builder $q) use ($searchTerm) {
            $q->whereRaw('similarity(name, ?) > 0.3', [$searchTerm])
                ->orWhere('name', 'ILIKE', "%{$searchTerm}%")
                ->orWhere('sku', 'ILIKE', "%{$searchTerm}%");
        });

        if (! isset($this->filters['sort'])) {
            $query->orderByRaw('similarity(name, ?) DESC', [$searchTerm]);
        }
    }

    private function applyFavoriteFilter(Builder $query): void
    {
        if (! isset($this->filters['is_favorite']) || ! Auth::check()) {
            return;
        }

        if ($this->filters['is_favorite'] === true) {
            $query->whereHas('favorites', function (Builder $q) {
                $q->whereHas('favoriteList', fn (Builder $subQ) => $subQ->where('user_id', Auth::id()));
            });
        } else {
            $query->whereDoesntHave('favorites', function (Builder $q) {
                $q->whereHas('favoriteList', fn (Builder $subQ) => $subQ->where('user_id', Auth::id()));
            });
        }
    }

    private function applySorting(Builder $query): void
    {
        if (! isset($this->filters['sort'])) {
            return;
        }

        $direction = $this->filters['sort_direction'] ?? 'asc';

        switch ($this->filters['sort']) {
            case 'category_name':
                $query->join('categories', 'products.category_id', '=', 'categories.id')
                    ->leftJoin('prices', function ($join) {
                        $join->on('products.id', '=', 'prices.product_id')
                            ->where('prices.is_active', true);
                    })
                    ->orderBy('categories.name', $direction)
                    ->select(
                        'products.*',
                        'prices.price as joined_price',
                        'prices.stock as joined_stock',
                        'prices.unit as joined_unit'
                    );
                break;

            case 'price':
            case 'stock':
                $query->leftJoin('prices', function ($join) {
                    $join->on('products.id', '=', 'prices.product_id')
                        ->where('prices.is_active', true);
                })
                    ->select(
                        'products.*',
                        'prices.price as joined_price',
                        'prices.stock as joined_stock',
                        'prices.unit as joined_unit'
                    )
                    ->orderBy('prices.'.$this->filters['sort'], $direction);
                break;

            default:
                $query->leftJoin('prices', function ($join) {
                    $join->on('products.id', '=', 'prices.product_id')
                        ->where('prices.is_active', true);
                })
                    ->select(
                        'products.*',
                        'prices.price as joined_price',
                        'prices.stock as joined_stock',
                        'prices.unit as joined_unit'
                    )
                    ->orderBy($this->filters['sort'], $direction);
        }
    }

    private function fetchCategories($ids, int $level): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return Category::whereIn('id', $ids)
            ->where('level', $level)
            ->select('id', 'name')
            ->get()
            ->toArray();
    }
}
