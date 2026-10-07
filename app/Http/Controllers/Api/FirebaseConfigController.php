<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirebaseConfigRequest;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group('Firebase', 'Manage the Firebase service account credentials used to send push notifications.', weight: 16)]
class FirebaseConfigController extends Controller
{

    /**
     * Get the Firebase credentials
     *
     * Reads the service account JSON file that the `FIREBASE_CREDENTIALS` environment variable points to.
     * Relative paths are resolved inside `storage/app`.
     */
    #[QueryParameter('full', 'Return the whole `private_key`; otherwise it is truncated to its first 40 characters.', type: 'bool', default: false)]
    #[Response(404, '`FIREBASE_CREDENTIALS` is not set or the file does not exist')]
    #[Response(422, 'The credentials file is not valid JSON')]
    #[Response(500, 'The credentials file cannot be read')]
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
            /**
             * Value of `FIREBASE_CREDENTIALS`.
             *
             * @var string
             */
            'env_value' => $envValue,
            /** Absolute path of the file read. */
            'resolved_path' => $path,
            /**
             * Contents of the service account JSON file.
             *
             * @var array<string, mixed>
             */
            'credentials' => $safe,
        ]);
    }

    /**
     * Save the Firebase credentials
     *
     * Saves the whole request body, the service account JSON downloaded from the Firebase console, as
     * `storage/app/private/firebase/credentials.json`, replacing the current file. That is the file
     * `FIREBASE_CREDENTIALS` points to by default; with another value, the saved file is not used.
     */
    public function update(FirebaseConfigRequest $request): JsonResponse
    {
        
        $data = $request->all();

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Storage::disk('local')->put('firebase/credentials.json', $json);

        Log::info('Firebase credentials stored', ['user_id' => optional($request->user())->id]);

        return response()->json(['message' => 'Firebase config saved'], 200);
    }

    
}