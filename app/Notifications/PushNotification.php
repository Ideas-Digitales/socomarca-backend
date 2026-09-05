<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

/**
 * Push notification delivered through FCM.
 *
 * Carries the id of the fcm_notification_histories row it comes from so the client
 * can tell this push apart from the copy it later reads from the history endpoint.
 */
class PushNotification extends Notification
{
    /** @var string Title shown in the push. */
    public $title;

    /** @var string Body shown in the push. */
    public $body;

    /** @var int|null Id of the fcm_notification_histories row backing this push. */
    public $notificationId;

    /**
     * @param string $title Title shown in the push
     * @param string $body Body shown in the push
     * @param int|null $notificationId Id of the history row this push comes from
     */
    public function __construct($title, $body, $notificationId = null)
    {
        $this->title = $title;
        $this->body = $body;
        $this->notificationId = $notificationId;
    }

    /**
     * Channels this notification is delivered on.
     *
     * @param mixed $notifiable The entity being notified
     * @return array<int, class-string> Channel classes
     */
    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    /**
     * Build the push message.
     *
     * 'notification_id' carries the fcm_notification_histories row this push comes
     * from. The client receives the same notification twice — once live through FCM
     * and once when it polls the history — and without this id it has no way to tell
     * that both are the same one, so it lists it twice.
     *
     * @param mixed $notifiable The entity being notified
     * @return FcmMessage The message handed to the FCM channel
     */
    public function toFcm($notifiable)
    {
        return FcmMessage::create()
            ->data(array_filter([
                'title' => $this->title,
                'body' => $this->body,
                // FCM only carries strings in the data payload.
                'notification_id' => $this->notificationId === null ? null : (string) $this->notificationId,
            ], fn ($value) => $value !== null))
            ->notification(
                FcmNotification::create($this->title)
                    ->body($this->body)
            );
    }
}
