<?php

namespace App\Services\Data;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
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
     * joined_price, joined_stock, joined_unit and joined_price_list_id from the prices table.
     *
     * A product repeats once per allowed price list (and per unit within a list), so
     * joined_price_list_id is what tells apart which list each row comes from.
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
            'prices.price_list_id as joined_price_list_id',
        );

        return $query->paginate($perPage);
    }

    /**
     * Build the query backing a filter sidebar facet.
     *
     * Sorting never changes which products match, and dropping it keeps the facet from
     * paying for an ORDER BY it does not use. A facet may also ignore its own filter
     * ($ignoredFilters): the brand list has to keep offering every brand of the search,
     * otherwise picking one brand would erase the rest and leave no way back.
     *
     * @param array<int, string> $ignoredFilters Filter keys to drop besides sorting
     * @return Builder
     */
    private function buildFacetQuery(array $ignoredFilters = []): Builder
    {
        $facetFilters = array_diff_key(
            $this->filters,
            array_flip(array_merge(['sort', 'sort_direction'], $ignoredFilters))
        );

        $service = new self($facetFilters);

        $query = $service->buildQuery();
        $service->joinAllowedPrices($query);

        return $query;
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
        $matchingProducts = $this->buildFacetQuery()
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
     * Get the brands present in the products matching the current filters, sorted by name.
     *
     * Feeds the brand section of the filter sidebar so that, while a search is active,
     * only the brands that actually own a matching product are offered. The brand filter
     * itself is ignored here (see buildFacetQuery) so a selected brand never hides the
     * other brands of the same search.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getMatchingBrands(): array
    {
        $brandIds = $this->buildFacetQuery(['brand_id'])
            ->select('brand_id')
            ->get()
            ->pluck('brand_id')
            ->filter()
            ->unique()
            ->values();

        if ($brandIds->isEmpty()) {
            return [];
        }

        return Brand::whereIn('id', $brandIds)
            ->orderBy('name')
            ->select('id', 'name')
            ->get()
            ->toArray();
    }

    /**
     * Join the prices table, restricted to the prices the current user may see.
     *
     * Visibility (active, in stock, zero-price exclusion and price lists) comes from
     * Price::applyVisibility() so this join and the category tree share one definition.
     * The optional price range and unit conditions are search filters, not visibility,
     * and stay here.
     *
     * @see \App\Models\Price::applyVisibility()
     * @param Builder $query The query builder to modify
     * @return void
     */
    private function joinAllowedPrices(Builder $query): void
    {
        $user = Auth::user();
        $priceFilter = $this->filters['price'] ?? null;

        $query->join('prices', function ($join) use ($user, $priceFilter) {
            $join->on('products.id', '=', 'prices.product_id');

            Price::applyVisibility($join, $user, 'prices');

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
