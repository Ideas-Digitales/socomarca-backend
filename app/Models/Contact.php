<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_code',
        'rut',
        'name',
        'email',
        'phone',
        'fax',
        'position',
        'area',
        'authorized',
        'address',
        'random_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized' => 'boolean',
            'random_synced_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Branch user this contact belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Chilean RUT stored as `body-dv`, without dots.
     */
    public static function normalizeRut(?string $rut): string
    {
        $clean = strtoupper((string) preg_replace('/[^0-9kK]/', '', (string) $rut));

        if (strlen($clean) < 2) {
            return $clean;
        }

        return substr($clean, 0, -1).'-'.substr($clean, -1);
    }

    /**
     * Map a `GET /web32/contactos` row into contact attributes. Null when the row has no entity, RUT or name.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function attributesFromRandom(array $row): ?array
    {
        $entityCode = trim((string) ($row['KOEN'] ?? ''));
        $rut = self::normalizeRut((string) ($row['RUTCONTACT'] ?? ''));
        $name = trim((string) ($row['NOKOCON'] ?? ''));

        if ($entityCode === '' || strlen($rut) < 3 || $name === '') {
            return null;
        }

        return [
            'entity_code' => $entityCode,
            'rut' => $rut,
            'name' => $name,
            'email' => User::normalizeEmail(isset($row['EMAILCON']) ? (string) $row['EMAILCON'] : null),
            'phone' => self::nullableString($row['FONOCON'] ?? null),
            'fax' => self::nullableString($row['FAXCON'] ?? null),
            'position' => self::nullableString($row['CARGOCON'] ?? null),
            'area' => self::nullableString($row['AREACON'] ?? null),
            'authorized' => self::booleanFromRandom($row['AUTORIZADO'] ?? false),
            'address' => self::nullableString($row['DIRECON'] ?? null),
            'random_synced_at' => now(),
        ];
    }

    /**
     * Body of one element of `PUT /web32/contactos`.
     *
     * @return array<string, mixed>
     */
    public function toRandomPayload(): array
    {
        return [
            'codigoEntidad' => $this->entity_code,
            'rutContacto' => $this->rut,
            'nombreContacto' => $this->name,
            'emailContacto' => $this->email,
            'fonoContacto' => $this->phone,
            'AUTORIZADO' => (bool) $this->authorized,
            'DIRECON' => $this->address,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function booleanFromRandom(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 's', 'si', 'yes'], true);
    }
}
