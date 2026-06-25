<?php

namespace App\Models;

use App\Enums\BranchType;
use App\Models\Scopes\CurrentUserScope;
use App\Models\Scopes\SecondaryBranchesScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'branch_type',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CurrentUserScope);
        static::addGlobalScope(new SecondaryBranchesScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
