<?php

namespace App\Services\Data;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductQueryService
{
    private array $filters;

    /**
     * Initialize the service with optional filters.
     *
     * @param array $filters Associative array of filter criteria (price, category_id, brand_id, etc.)
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Get paginated products with prices joined from user's allowed price lists.
     *
     * Returns one row per product-price combination (variant). Each variant includes
     * joined_price, joined_stock, and joined_unit from the prices table.
     *
     * @param int $perPage Number of items per page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPaginatedProductsWithAllowedPrices(int $perPage = 20)
    {
        $query = $this->buildQuery();
        $this->joinAllowedPrices($query);

        $query->select(
            'products.*',
            'prices.price as joined_price',
            'prices.stock as joined_stock',
            'prices.unit as joined_unit',
        );

        return $query->paginate($perPage);
    }

    /**
     * Get unique categories (supercategories, categories, subcategories) from products matching current filters.
     *
     * Useful for building faceted navigation/filter sidebar. Excludes sort filters to ensure
     * all matching products are considered regardless of sort order.
     *
     * @return array{supercategories: array, categories: array, subcategories: array}
     */
    public function getMatchingCategories(): array
    {
        $filtersWithoutSort = array_diff_key($this->filters, array_flip(['sort', 'sort_direction']));

        $service = new self($filtersWithoutSort);

        $query = $service->buildQuery();
        $service->joinAllowedPrices($query);

        $matchingProducts = $query
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

    /**
     * Join the prices table with conditions for user's allowed price lists.
     *
     * Applies constraints: active prices, stock > 0, user's price lists, optional price range/unit filters.
     * Excludes zero-price products unless config('random.show_product_zero_price') is true.
     *
     * @param Builder $query The query builder to modify
     */
    private function joinAllowedPrices(Builder $query): void
    {
        $user = Auth::user();
        // $priceLists = json_decode($user->prices_lists);
        $priceLists = $user->prices_lists;
        $priceFilter = $this->filters['price'] ?? null;

        $query->join('prices', function ($join) use ($priceLists, $priceFilter) {
            $join->on('products.id', '=', 'prices.product_id')
                ->where('prices.is_active', true)
                ->where('prices.stock', '>', 0)
                ->whereIn('prices.price_list_id', $priceLists);

            if (! config('random.show_product_zero_price')) {
                $join->where('prices.price', '>', 0);
            }

            if ($priceFilter) {
                if (isset($priceFilter['min'])) {
                    $join->where('prices.price', '>=', $priceFilter['min']);
                }
                if (isset($priceFilter['max'])) {
                    $join->where('prices.price', '<=', $priceFilter['max']);
                }
                if (isset($priceFilter['unit'])) {
                    $join->where('prices.unit', $priceFilter['unit']);
                }
            }
        });
    }

    /**
     * Build the base product query with all filters applied (except price join).
     *
     * Applies: status filter, supercategory, category, subcategory, brand, SKU, name,
     * favorite, and sorting filters.
     *
     * @return Builder
     */
    private function buildQuery(): Builder
    {
        $query = Product::query()->where('status', true);

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

    /**
     * Filter products by supercategory IDs.
     *
     * @param Builder $query The query builder to modify
     */
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

    /**
     * Filter products by category IDs.
     *
     * @param Builder $query The query builder to modify
     */
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

    /**
     * Filter products by subcategory IDs.
     *
     * @param Builder $query The query builder to modify
     */
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

    /**
     * Filter products by brand IDs.
     *
     * @param Builder $query The query builder to modify
     */
    private function applyBrandFilter(Builder $query): void
    {
        if (! isset($this->filters['brand_id'])) {
            return;
        }

        $query->whereIn('brand_id', $this->filters['brand_id']);
    }

    /**
     * Filter products by exact SKU match.
     *
     * @param Builder $query The query builder to modify
     */
    private function applySkuFilter(Builder $query): void
    {
        if (! isset($this->filters['sku'])) {
            return;
        }

        $query->where('sku', $this->filters['sku']);
    }

    /**
     * Filter products by name using fuzzy search (PostgreSQL similarity) and partial matching.
     *
     * Searches product name and SKU using ILIKE and similarity functions.
     * Results are ordered by similarity score unless a sort filter is provided.
     *
     * @param Builder $query The query builder to modify
     */
    private function applyNameFilter(Builder $query): void
    {
        if (! isset($this->filters['name'])) {
            return;
        }

        $searchTerm = $this->filters['name'];

        $query->where(function (Builder $q) use ($searchTerm) {
            $q->whereRaw('word_similarity(?, name) > 0.5', [$searchTerm])
                ->orWhere('name', 'ILIKE', "%{$searchTerm}%")
                ->orWhere('sku', 'ILIKE', "%{$searchTerm}%");
        });

        if (! isset($this->filters['sort'])) {
            $query->orderByRaw('word_similarity(?, name) DESC', [$searchTerm]);
        }
    }

    /**
     * Filter products by favorite status for the authenticated user.
     *
     * When is_favorite is true, only products in user's favorite lists are returned.
     * When is_favorite is false, only products NOT in user's favorite lists are returned.
     *
     * @param Builder $query The query builder to modify
     */
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

    /**
     * Apply sorting to the query based on sort field and direction.
     *
     * Supported sort fields: category_name (joins categories table), price, stock (uses joined aliases),
     * and any product column (id, name, etc.).
     *
     * @param Builder $query The query builder to modify
     */
    private function applySorting(Builder $query): void
    {
        if (! isset($this->filters['sort'])) {
            return;
        }

        $direction = $this->filters['sort_direction'] ?? 'asc';

        switch ($this->filters['sort']) {
            case 'category_name':
                $query->join('categories', 'products.category_id', '=', 'categories.id')
                    ->orderBy('categories.name', $direction);
                break;

            case 'price':
                $query->orderBy('joined_price', $direction);
                break;

            case 'stock':
                $query->orderBy('joined_stock', $direction);
                break;

            default:
                $query->orderBy($this->filters['sort'], $direction);
        }
    }

    /**
     * Fetch categories by IDs and level.
     *
     * @param \Illuminate\Support\Collection $ids Collection of category IDs
     * @param int $level Category level (1 = supercategory, 2 = category, 3 = subcategory)
     * @return array Array of categories with id and name
     */
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
