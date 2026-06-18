<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Product extends Model
{

    use HasFactory;

    protected $fillable = [
        'random_product_id',
        'name',
        'description',
        'supercategory_id',
        'category_id',
        'subcategory_id',
        'brand_id',
        'sku',
        'status',
        'price_id'
    ];

    /**
     * @var array
     * Allowed filters for the filter scope.
     */
    protected $allowedFilters = [
        [
            'field' => 'supercategory_id',
            'operators' => ['=', '!=',],
        ],
        [
            'field' => 'category_id',
            'operators' => ['=', '!=',],
        ],
        [
            'field' => 'subcategory_id',
            'operators' => ['=', '!=',],
        ],
        [
            'field' => 'brand_id',
            'operators' => ['=', '!=',],
        ],
        [
            'field' => 'is_favorite',
            'operators' => ['='],
        ],
        [
            'field' => 'name',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE', 'fulltext'],
        ],
    ];

    /**
     * @var array
     * Allowed sorts for the filter scope.
     */
    protected $allowedSorts = [
        'name',
        'created_at',
        'updated_at',
    ];

    public function supercategory()
    {
        return $this->belongsTo(Category::class, 'supercategory_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function prices()
    {
        return $this->hasMany(Price::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function userFavorites($userId)
    {
        return $this->hasMany(Favorite::class)
            ->whereHas('favoriteList', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    /**
     * Scope a query to filter products based on given criteria
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter($query, array $filters)
    {
        // Filtro de Precio
        if (isset($filters['price'])) {
            $priceFilter = $filters['price'];
            $query->whereHas('prices', function ($q) use ($priceFilter) {
                if (isset($priceFilter['min'])) {
                    $q->where('price', '>=', $priceFilter['min']);
                }
                if (isset($priceFilter['max'])) {
                    $q->where('price', '<=', $priceFilter['max']);
                }
                $q->where('is_active', true);

                // Opcional: filtrar por unidad si se envía
                if (isset($priceFilter['unit'])) {
                    $q->where('unit', $priceFilter['unit']);
                }
            });
        }

        // Filtro para ocultar/mostrar productos con precio 0
        if (!config('random.show_product_zero_price')) {
            $query->whereHas('prices', function ($q) {
                $q->where('price', '>', 0)
                  ->where('is_active', true);
            });
        }

        // Filtro para ocultar productos sin stock
        $query->whereHas('prices', function ($q) {
            $q->where('stock', '>', 0)
              ->where('is_active', true);
        });

        // Filtro de Super Categoría
        if (isset($filters['supercategory_id'])) {
            $supercategoryIds = is_array($filters['supercategory_id']) ? $filters['supercategory_id'] : [$filters['supercategory_id']];
            $query->whereIn('supercategory_id', $supercategoryIds);
        }

        // Filtro de Categoría
        if (isset($filters['category_id'])) {
            $categoryIds = is_array($filters['category_id']) ? $filters['category_id'] : [$filters['category_id']];
            $query->whereIn('category_id', $categoryIds);
        }

        // Filtro de Subcategoría
        if (isset($filters['subcategory_id'])) {
            $subcategoryIds = is_array($filters['subcategory_id']) ? $filters['subcategory_id'] : [$filters['subcategory_id']];
            $query->whereIn('subcategory_id', $subcategoryIds);
        }

        // Filtro de Marca
        if (isset($filters['brand_id'])) {
            $query->whereIn('brand_id', $filters['brand_id']);
        }

        // Filtro por SKU
        if (isset($filters['sku'])) {
            $query->where('sku', $filters['sku']);
        }

        // Filtro por Nombre (búsqueda parcial)
        if (isset($filters['name'])) {
            $searchTerm = $filters['name'];
            $query->where(function($q) use ($searchTerm) {
                $q->whereRaw('similarity(name, ?) > 0.3', [$searchTerm])
                  ->orWhere('name', 'ILIKE', "%{$searchTerm}%")
                  ->orWhere('sku', 'ILIKE', "%{$searchTerm}%");
            });

            // Solo aplica el orderByRaw si NO hay sort definido
            if (!isset($filters['sort'])) {
                $query->orderByRaw('similarity(name, ?) DESC', [$searchTerm]);
            }
        }

        // Filtro de Favoritos
        if (isset($filters['is_favorite']) && Auth::check()) {
            if ($filters['is_favorite'] === true) {
                $query->whereHas('favorites', function ($q) {
                    $q->whereHas('favoriteList', fn($subQ) => $subQ->where('user_id', Auth::id()));
                });
            } else {
                $query->whereDoesntHave('favorites', function ($q) {
                    $q->whereHas('favoriteList', fn($subQ) => $subQ->where('user_id', Auth::id()));
                });
            }
        }

        // Ordenamiento opcional
        if (isset($filters['sort'])) {
            $direction = $filters['sort_direction'] ?? 'asc';
            switch ($filters['sort']) {
                case 'category_name':
                    $query->join('categories', 'products.category_id', '=', 'categories.id')
                          ->leftJoin('prices', function($join) {
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
                    $query->leftJoin('prices', function($join) {
                            $join->on('products.id', '=', 'prices.product_id')
                                 ->where('prices.is_active', true);
                        })
                        ->select(
                            'products.*',
                            'prices.price as joined_price',
                            'prices.stock as joined_stock',
                            'prices.unit as joined_unit'
                        )
                        ->orderBy('prices.' . $filters['sort'], $direction);
                    break;
                default:
                    $query->leftJoin('prices', function($join) {
                            $join->on('products.id', '=', 'prices.product_id')
                                 ->where('prices.is_active', true);
                        })
                        ->select(
                            'products.*',
                            'prices.price as joined_price',
                            'prices.stock as joined_stock',
                            'prices.unit as joined_unit'
                        )
                        ->orderBy($filters['sort'], $direction);
            }
        }

        return $query;
    }

    /**
     * Scope a query to filter active products
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        $query->where('status', '=', true);
        return $query;
    }
}
