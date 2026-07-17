<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\PasswordRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use App\Mail\TemporaryPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Rules\ValidateRut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PasswordResetController extends Controller
{
    public function forgotPassword(PasswordRequest $request)
    {
        $user = $request->user;

        if ($user->email == null) {
            $tokenName = $request->device_name ?? 'unknown-device';
            $token = $user->createToken($tokenName, ['credentials-restore'])->plainTextToken;

            return response()->json([
                'message' => __('auth.missing_email'),
                'data' => [
                    'email' => null,
                    'missing_email' => true,
                    'provisional_token' => $token,
                ]
            ]);
        }

        // Generar contraseña temporal alfanumérica de 8 caracteres
        $temporaryPassword = Str::random(8);

        // Actualizar la contraseña del usuario en la base de datos
        $user->password = Hash::make($temporaryPassword);
        $user->password_changed_at = null; // Para forzar el cambio de contraseña en el próximo login
        $user->save();

        Mail::to($user->email)->send(new TemporaryPasswordMail($user, $temporaryPassword));

        return response()->json([
            'message' => __('auth.password_reset'),
            'data' => [
                'email' => $user->email,
                'temporary_password' => $temporaryPassword
            ]
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


    /**
     * Verificar token por RUT en lugar de email
     */
    public function verifyTokenByRut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'rut' => ['required|exists:users,rut', new ValidateRut()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Buscar al usuario por RUT para obtener su email
        $user = User::where('rut', $request->rut)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'No se encontró un usuario con ese RUT',
                'errors' => ['rut' => ['Usuario no encontrado']]
            ], 404);
        }

        // Verificar si existe una entrada en la tabla password_reset_tokens
        $tokenData = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->first();

        if (!$tokenData) {
            return response()->json([
                'status' => false,
                'message' => 'Token inválido o expirado',
                'valid' => false
            ], 400);
        }

        // Verificar si el token es válido
        $valid = Hash::check($request->token, $tokenData->token);

        return response()->json([
            'status' => true,
            'message' => $valid ? 'Token válido' : 'Token inválido',
            'valid' => $valid
        ]);
    }

    /**
     * Restablecer la contraseña por RUT
     */
    public function resetPasswordByRut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'rut' => ['required|exists:users,rut', new ValidateRut()],
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Buscar al usuario por RUT para obtener su email
        $user = User::where('rut', $request->rut)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'No se encontró un usuario con ese RUT',
                'errors' => ['rut' => ['Usuario no encontrado']]
            ], 404);
        }

        // Restablecer la contraseña usando el email asociado al RUT
        $status = Password::reset(
            [
                'email' => $user->email,
                'password' => $request->password,
                'password_confirmation' => $request->password_confirmation,
                'token' => $request->token
            ],
            function (User $resetUser, string $password) {
                $resetUser->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($resetUser));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => true,
                'message' => 'Contraseña restablecida correctamente'
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'No se pudo restablecer la contraseña',
            'errors' => ['rut' => [trans($status)]]
        ], 400);
    }
}
