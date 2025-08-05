<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static find($id)
 */
class FavoriteList extends Model
{
    use HasFactory;

    protected $table = 'favorites_list';

    protected $fillable = [
        'name',
        'user_id',
    ];

    // Relación con User
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
