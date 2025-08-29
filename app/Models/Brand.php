<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['random_erp_code', 'name', 'description', 'logo_url'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}