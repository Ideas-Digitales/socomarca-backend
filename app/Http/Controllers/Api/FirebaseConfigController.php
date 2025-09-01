<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirebaseConfigRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FirebaseConfigController extends Controller
{

    public function showConfig(Request $request): JsonResponse
    {
        $envValue = env('FIREBASE_CREDENTIALS');
        if (empty($envValue)) {
            return response()->json(['ok' => false, 'message' => 'FIREBASE_CREDENTIALS env not set'], 404);
        }

        $path = $envValue;
        if (! file_exists($path)) {
            if (str_starts_with($path, 'storage/app/')) {
                $candidate = storage_path('app/' . substr($path, strlen('storage/app/')));
            } elseif (str_starts_with($path, 'storage/')) {
                $candidate = storage_path(substr($path, strlen('storage/')));
            } else {
                $candidate = storage_path('app/' . ltrim($path, '/'));
            }
            $path = $candidate;
        }

        if (! file_exists($path)) {
            return response()->json([
                'ok' => false,
                'env_value' => $envValue,
                'resolved_path' => $path,
                'message' => 'Credentials file not found'
            ], 404);
        }

        try {
            $raw = file_get_contents($path);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Cannot read file',
                'resolved_path' => $path,
                'error' => $e->getMessage(),
            ], 500);
        }

        if ($raw === false) {
            return response()->json([
                'ok' => false,
                'message' => 'Cannot read file',
                'resolved_path' => $path,
            ], 500);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid JSON in credentials file',
                'resolved_path' => $path,
                'json_error' => json_last_error_msg()
            ], 422);
        }

        $safe = $decoded;
        if (isset($safe['private_key']) && ! $request->boolean('full')) {
            $pk = $safe['private_key'];
            $safe['private_key'] = substr($pk, 0, 40) . '...[truncated]';
        }

        return response()->json([
            'ok' => true,
            'env_value' => $envValue,
            'resolved_path' => $path,
            'credentials' => $safe,
        ]);
    }
    public function update(FirebaseConfigRequest $request): JsonResponse
    {
        
        $data = $request->all();

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Storage::disk('local')->put('firebase/credentials.json', $json);

        Log::info('Firebase credentials stored', ['user_id' => optional($request->user())->id]);

        return response()->json(['message' => 'Firebase config saved'], 200);
    }

    
}