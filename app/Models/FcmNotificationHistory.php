<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcmNotificationHistory extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'sent_at',
    ];

    public $timestamps = false;
}