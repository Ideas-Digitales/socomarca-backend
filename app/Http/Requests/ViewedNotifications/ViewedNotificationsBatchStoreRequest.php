<?php

namespace App\Http\Requests\ViewedNotifications;

use Illuminate\Foundation\Http\FormRequest;

class ViewedNotificationsBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resources' => 'required|array|min:1',
            'resources.*.fcm_notification_id' => 'required|integer|exists:fcm_notification_histories,id',
        ];
    }

    public function messages(): array
    {
        return [
            'resources.required' => 'El campo resources es requerido',
            'resources.array' => 'El campo resources debe ser un array',
            'resources.min' => 'Debe proporcionar al menos una notificación',
            'resources.*.fcm_notification_id.required' => 'El fcm_notification_id es requerido',
            'resources.*.fcm_notification_id.integer' => 'El fcm_notification_id debe ser un número entero',
            'resources.*.fcm_notification_id.exists' => 'La notificación especificada no existe',
        ];
    }
}
