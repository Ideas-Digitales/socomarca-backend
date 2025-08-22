<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirebaseConfigRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FirebaseConfigController extends Controller
{
    public function update(FirebaseConfigRequest $request): JsonResponse
    {
        
        $data = $request->all();

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Storage::disk('local')->put('firebase/credentials.json', $json);

        Log::info('Firebase credentials stored', ['user_id' => optional($request->user())->id]);

        return response()->json(['message' => 'Firebase config saved'], 200);
    }
}