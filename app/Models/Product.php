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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
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

        // Filtro de Categoría
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        // Filtro de Subcategoría
        if (isset($filters['subcategory_id'])) {
            $query->where('subcategory_id', $filters['subcategory_id']);
        }

        // Filtro de Marca
        if (isset($filters['brand_id'])) {
            $query->whereIn('brand_id', $filters['brand_id']);
        }
        
        // Filtro por Nombre (búsqueda parcial)
        if (isset($filters['name'])) {
            $searchTerm = $filters['name'];
            $query->where(function($q) use ($searchTerm) {
                $q->whereRaw('similarity(name, ?) > 0.3', [$searchTerm])
                  ->orWhere('name', 'ILIKE', "%{$searchTerm}%");
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
}