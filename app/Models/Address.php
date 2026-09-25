<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'region_id',
        'address_line1',
        'address_line2',
        'municipality_id',
        'postal_code',
        'is_default',
        'type',
        'phone',
        'contact_name',
        'alias',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }
}