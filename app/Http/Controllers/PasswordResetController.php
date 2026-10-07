<?php

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use App\Http\Requests\PasswordRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Mail\TemporaryPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Services\Security\PasswordGeneratorService;
use App\Models\User;
use Illuminate\Support\Str;

#[Group('Authentication')]
class PasswordResetController extends Controller
{
    public function __construct(
        public PasswordGeneratorService $passwordService
    ) {}

    /**
     * Request a temporary password
     *
     * Emails a temporary password only when the email belongs to exactly one active user; the user must
     * change it after logging in. The response is the same in every case (with the requested email masked),
     * so that it does not reveal whether the email is registered.
     *
     * Limited to 6 requests per minute.
     *
     * @unauthenticated
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
            /** @var string */
            'message' => __('auth.password_reset', ['email' => $maskedEmail]),
            'data' => [
                /**
                 * Requested email, masked.
                 *
                 * @example c*****s@cliente.cl
                 */
                'email' => $maskedEmail,
            ],
        ]);
    }


    /**
     * Change the password
     *
     * Changes the authenticated user's password, which also clears the pending password change of a
     * temporary password.
     */
    #[BodyParameter('revoke_all_tokens', 'Revoke every other token of the user (other sessions), keeping the current one.', required: false, type: 'bool', default: false)]
    #[Response(400, 'The current password is wrong', type: 'array{status: false, message: string, errors: array{current_password: list<string>}}')]
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            /** New password. Send it again as `password_confirmation`. */
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
     * Get the password status
     *
     * Tells whether the authenticated user must change the password, as after logging in with a
     * temporary password.
     */
    public function checkPasswordStatus(Request $request)
    {
        $user = $request->user();
        $needsChange = $user->password_changed_at === null;

        return response()->json([
            'status' => true,
            'data' => [
                /** @var bool */
                'needs_password_change' => $needsChange
            ]
        ]);
    }
}
