<?php

namespace App\Models;

use App\Models\Scopes\CurrentUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy(CurrentUserScope::class)]
class Branch extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'user_code',
        'email',
        'commercial_email',
        'phone',
        'rut',
        'business_name',
        'user_id',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
