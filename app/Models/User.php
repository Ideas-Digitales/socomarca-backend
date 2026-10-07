<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BranchType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        'branch_code',
        'user_code',
        'random_user_type',
        'prices_lists',
        'random_entity_id',
        'branch_type',
        'billing_email',
        'random_synced_at',
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
            'prices_lists' => 'array',
            'random_entity_id' => 'integer',
            'random_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('is_active') && !$user->is_active) {
                $user->tokens()->delete();
            }
        });
    }

    /**
     * Emails are stored normalized so that lookups and duplicate detection are case-insensitive.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normalizeEmail($value),
        );
    }

    /**
     * Normalize an email address (trimmed and lowercase). Blank values are normalized to null.
     *
     * @param string|null $email
     * @return string|null
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * Find the active user that can authenticate with the given email.
     *
     * Returns null when no active user has the email or when it is assigned to more than one
     * active user: a shared email does not identify a single user. Inactive users are ignored,
     * as in the email issues alert of the Random entities sync.
     *
     * @param string|null $email
     * @return User|null
     */
    public static function findActiveByLoginEmail(?string $email): ?User
    {
        $email = self::normalizeEmail($email);

        if ($email === null) {
            return null;
        }

        $users = self::where('is_active', true)
            ->whereRaw('lower(trim(email)) = ?', [$email])
            ->limit(2)
            ->get();

        if ($users->count() > 1) {
            Log::warning('Authentication denied: email assigned to more than one active user', [
                'user_ids' => $users->pluck('id')->all(),
            ]);
        }

        return $users->count() === 1 ? $users->first() : null;
    }

    /**
     * Whether the user is synced from a Random entity, so that its data is managed by the sync.
     */
    public function isSyncedFromRandom(): bool
    {
        return $this->random_entity_id !== null;
    }

    /**
     * Secondary branches of the same Random entity (KOEN).
     */
    public function secondaryBranches(): HasMany
    {
        return $this->hasMany(User::class, 'user_code', 'user_code')
            ->where('branch_type', BranchType::SECONDARY);
    }

    /**
     * Branches the user can place orders for, besides itself (see OrderPolicy::placeFor):
     * the active secondary branches of its Random entity when it is a primary branch, none otherwise.
     */
    public function orderableBranches(): HasMany
    {
        return $this->secondaryBranches()
            ->where('is_active', true)
            ->when($this->branch_type !== BranchType::PRIMARY, fn ($query) => $query->whereRaw('false'));
    }

    /**
     * Whether the user can place orders for other branches of its Random entity.
     */
    public function canOrderForBranches(): bool
    {
        return $this->orderableBranches()->exists();
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

    /**
     * Random documents relationship
     */
    public function randomDocuments(): MorphMany
    {
        return $this->morphMany(RandomDocument::class, 'documentable');
    }

    /**
     * Credit lines relationship
     */
    public function creditLines(): HasMany
    {
        return $this->hasMany(CreditLine::class);
    }

    /**
     * Determine whether product and price visibility must be limited to the user's own price lists.
     *
     * Users holding 'read-all-products' (superadmin, admin, supervisor, editor) see every
     * product regardless of price list; users holding only 'read-price-list-products'
     * (customer) are restricted to the lists assigned to them.
     *
     * @return bool True when the user may only see prices from its own price lists.
     */
    public function restrictedToOwnPriceLists(): bool
    {
        return ! $this->can('read-all-products');
    }
}
