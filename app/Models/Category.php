<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'code', 'level', 'key'];

    /**
     * @var array
     * Allowed filters for the filter scope.
     */
    protected $allowedFilters = [
        [
            'field' => 'name',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE', 'fulltext'],
        ],
        [
            'field' => 'description',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE', 'fulltext'],
        ],
        [
            'field' => 'code',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE'],
        ],
        [
            'field' => 'level',
            'operators' => ['=', '!=', '>', '<', '>=', '<='],
        ],
    ];

    /**
     * @var array
     * Allowed sorts for the filter scope.
     */
    protected $allowedSorts = [
        'name',
        'description',
        'code',
        'level',
        'created_at',
        'updated_at',
    ];

    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
        ];
    }

    /**
     * Scope a query to filter categories based on given criteria
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter($query, array $filters)
    {
        foreach ($filters as $filter) {
            $field = array_find($this->allowedFilters, function ($item) use ($filter) {
                return $item['field'] === $filter['field'];
            });

            if ($field !== null) {
                $value = $filter['value'];
                $operator = array_find($field['operators'], function ($item) use ($filter) {
                    return $item === $filter['operator'];
                });

                if ($operator !== null && $operator !== 'fulltext') {
                    $query->where($field['field'], $operator, $value);
                } elseif ($operator === 'fulltext') {
                    $query
                        ->selectRaw("similarity(categories.{$field['field']}, ?) AS similarity_index", [$value])
                        ->whereRaw("categories.{$field['field']} % ?", [$value])
                        ->orderBy("similarity_index", "DESC");
                }

                if (
                    isset($filter['sort'])
                    && in_array($filter['field'], $this->allowedSorts)
                    && in_array($filter['sort'], ['ASC', 'DESC'])
                ) {
                    $query->orderBy($filter['field'], $filter['sort']);
                }
            }
        }

        return $query;
    }
}