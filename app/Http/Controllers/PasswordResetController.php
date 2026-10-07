<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\PasswordRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Mail\TemporaryPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Services\Security\PasswordGeneratorService;
use App\Models\User;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function __construct(
        public PasswordGeneratorService $passwordService
    ) {}

    /**
     * Enviar una contraseña temporal al email, solo si corresponde a exactamente un usuario activo.
     *
     * La respuesta es la misma en todos los casos (con el email solicitado enmascarado), para no
     * revelar si el email está registrado.
     */
    public function forgotPassword(PasswordRequest $request)
    {
        $user = $request->user;

        if ($user !== null) {
            // Generar contraseña temporal alfanumérica de 8 caracteres
            $passwordDto = $this->passwordService->generate();
            $temporaryPassword = $passwordDto->password;

            // Actualizar la contraseña del usuario en la base de datos
            $user->password = $passwordDto->passwordHash;
            $user->password_changed_at = null; // Para forzar el cambio de contraseña en el próximo login
            $user->save();

            Mail::to($user->email)->send(new TemporaryPasswordMail($user, $temporaryPassword));
        }

        $maskedEmail = Str::maskEmail(User::normalizeEmail($request->input('email')) ?? '');

        return response()->json([
            'message' => __('auth.password_reset', ['email' => $maskedEmail]),
            'data' => [
                'email' => $maskedEmail,
            ],
        ]);
    }


    /**
     * Cambiar contraseña (requiere autenticación)
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'La contraseña actual es incorrecta',
                'errors' => ['current_password' => ['La contraseña actual es incorrecta']]
            ], 400);
        }

        // Actualizar la contraseña
        $user->password = Hash::make($request->password);
        $user->password_changed_at = \Carbon\Carbon::now();
        $user->save();

        // Opcionalmente, revocar todos los tokens excepto el actual
        if ($request->has('revoke_all_tokens') && $request->revoke_all_tokens) {
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        }

        return response()->json([
            'status' => true,
            'message' => 'Contraseña actualizada correctamente'
        ]);
    }

    /**
     * Verificar si el usuario necesita cambiar su contraseña
     */
    public function checkPasswordStatus(Request $request)
    {
        $user = $request->user();
        $needsChange = $user->password_changed_at === null;

        return response()->json([
            'status' => true,
            'data' => [
                'needs_password_change' => $needsChange
            ]
        ]);
    }
}
