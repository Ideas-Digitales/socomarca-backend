<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @method static find(mixed $id)
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'rut',
        'business_name',
        'is_active',
        'last_login',
        'password_changed_at',
        'fcm_token',
    ];

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
            'field' => 'email',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE'],
        ],
        [
            'field' => 'rut',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE'],
        ],
        [
            'field' => 'business_name',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE', 'fulltext'],
        ],
        [
            'field' => 'is_active',
            'operators' => ['=', '!='],
        ],
        [
            'field' => 'phone',
            'operators' => ['=', '!=', 'LIKE', 'ILIKE', 'NOT LIKE'],
        ],
    ];

    /**
     * @var array
     * Allowed sorts for the filter scope.
     */
    protected $allowedSorts = [
        'id',
        'name',
        'email',
        'rut',
        'business_name',
        'is_active',
        'phone',
        'last_login',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Retorna solo la dirección de facturación
     *
     * @return [type]
     */
    public function billing_address()
    {
        return $this->hasOne(Address::class)->where('type', 'billing');
    }

    /**
     * Retorna las direcciones de envío
     *
     * @return [type]
     */
    public function shipping_addresses()
    {
        return $this->hasMany(Address::class)
            ->where('type', 'shipping');
    }

    public function default_shipping_address()
    {
        return $this->hasOne(Address::class)
            ->where('type', 'shipping')
            ->where('is_default', 1);
    }

    public function favoritesList()
    {
        return $this->hasMany(FavoriteList::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'business_name' => $this->business_name,
            'rut' => $this->rut,
        ];
    }

    /**
     * Scope a query to filter users based on given criteria
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter($query, array $filters)
    {
        foreach ($filters as $filter) {
            if ($filter['field'] === 'role' && !empty($filter['value'])) {
                if (($filter['operator'] ?? '=') === 'IN' && is_array($filter['value'])) {
                    $query->role($filter['value']);
                } else {
                    $query->role($filter['value']);
                }
                continue;
            }

            // Filtro especial para buscar en name o email
            if ($filter['field'] === 'name_or_email' && !empty($filter['value'])) {
                $value = $filter['value'];
                $operator = $filter['operator'] ?? 'ILIKE';

                // Si es un operador LIKE/ILIKE, añadir los wildcards
                if (in_array($operator, ['LIKE', 'ILIKE'])) {
                    $searchValue = "%{$value}%";
                } else {
                    $searchValue = $value;
                }

                $query->where(function ($q) use ($operator, $searchValue) {
                    $q->where('name', $operator, $searchValue)
                      ->orWhere('email', $operator, $searchValue);
                });
                continue;
            }

            $field = array_find($this->allowedFilters, function ($item) use ($filter) {
                return $item['field'] === $filter['field'];
            });

            if ($field !== null) {
                $value = $filter['value'];
                $operator = array_find($field['operators'], function ($item) use ($filter) {
                    return $item === ($filter['operator'] ?? '=');
                });

                // Si no se especifica operador, usar '=' por defecto
                if ($operator === null && empty($filter['operator'])) {
                    $operator = '=';
                }

                if ($operator !== null && $operator !== 'fulltext') {
                    $query->where($field['field'], $operator, $value);
                } elseif ($operator === 'fulltext') {
                    // Verificar si pg_trgm está disponible
                    if ($this->isPgTrgmAvailable()) {
                        $query
                            ->selectRaw("similarity(users.{$field['field']}, ?) AS similarity_index", [$value])
                            ->whereRaw("users.{$field['field']} % ?", [$value])
                            ->orderBy("similarity_index", "DESC");
                    } else {
                        // Fallback a ILIKE si pg_trgm no está disponible
                        $query->where($field['field'], 'ILIKE', "%{$value}%");
                    }
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

    /**
     * Verificar si la extensión pg_trgm está disponible en PostgreSQL
     *
     * @return bool
     */
    private function isPgTrgmAvailable(): bool
    {
        try {
            $result = DB::select("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'");
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function routeNotificationForFcm()
    {
        return $this->fcm_token;
    }
}
