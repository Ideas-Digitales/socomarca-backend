<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\UpdateRequest;
use App\Mail\TemporaryPasswordMail;
use App\Services\Security\PasswordGeneratorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CredentialController extends Controller
{
    public function __construct(private PasswordGeneratorService $passwordService)
    {
    }

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
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        $temporaryPassword = $this->passwordService->generate();
        $user->email = $request->email;
        $user->password = Hash::make($temporaryPassword);
        $user->password_changed_at = null; // Force password change ?
        $user->save();

        Mail::to($user->email)->send(new TemporaryPasswordMail($user, $temporaryPassword));

        return response()->json([
            'message' => __('auth.password_reset'),
        ]);
    }
}
