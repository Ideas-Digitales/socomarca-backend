<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\UpdateRequest;
use Illuminate\Support\Facades\Hash;

class CredentialController extends Controller
{
    /**
     * Update current user credentials.
     *
     * @param UpdateRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateRequest $request)
    {
        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => __('auth.password'),
                'errors' => ['current_password' => [__('auth.password')]]
            ], 400);
        }
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
            $user->password_changed_at = \Carbon\Carbon::now();
        }

        if ($request->has('email')) {
            $user->email = $request->email;
        }

        $user->save();

        if ($request->has('revoke_all_tokens') && $request->revoke_all_tokens) {
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        }

        return response()->json([
            'status' => true,
            'message' => __('auth.credentials_update'),
        ]);
    }
}
