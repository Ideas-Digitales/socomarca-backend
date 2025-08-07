<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        // Obtener todos los usuarios con rol customer
        $customers = User::role('customer')->get();

        // Enviar notificación por correo a cada customer
        foreach ($customers as $customer) {
            Mail::queue(new NotificationMail(
                $customer,
                $validated['title'],
                $validated['message']
            ));
        }

        return response()->json([
            'message' => 'Notification sent successfully',
            'data' => [
                'title' => $validated['title'],
                'message' => $validated['message'],
                'recipients_count' => $customers->count(),
                'created_at' => now()->toISOString(),
            ]
        ], 201);
    }
}