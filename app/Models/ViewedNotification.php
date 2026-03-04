<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViewedNotification extends Model
{
    protected $fillable = [
        'fcm_notification_id',
        'user_id',
    ];

    public function fcmNotification(): BelongsTo
    {
        return $this->belongsTo(FcmNotificationHistory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
