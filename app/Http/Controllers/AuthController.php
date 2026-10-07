<?php

namespace App\Http\Controllers;


use Carbon\Carbon;
use App\Http\Requests\AuthRequest;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

#[Group('Authentication', 'Log in with email and password and manage the session token and password.', weight: 0)]
class AuthController extends Controller
{

    /**
     * Log in
     *
     * Issues an API token for an active user. Customers log in with their commercial email (Random ERP
     * `EMAILCOMER`). The email is case-insensitive.
     *
     * Unknown or inactive emails, emails assigned to more than one active user and wrong passwords
     * all respond 401, so that the response does not reveal whether an email is registered.
     *
     * @unauthenticated
     */
    #[BodyParameter('device_name', 'Name of the device, stored as the token name.', required: false, type: 'string', example: 'iPhone de Juan')]
    #[Response(401, 'Invalid credentials', type: 'array{message: "Unauthorized"}')]
    public function login(AuthRequest $request)
    {
        $user = $request->auth_user;

        $user->update([
            'last_login' => Carbon::now()
        ]);


        // Crear token con el nombre del dispositivo

        $tokenName = $request->device_name ?? 'unknown-device';
        $token = $user->createToken($tokenName, ['api-access'])->plainTextToken;

        // Obtener roles y permisos
        $roles = $user->getRoleNames();
        $permissions = $user->getAllPermissions()->pluck('name');
        $response = [
            /** Bearer token to send in the `Authorization` header. */
            'token' => $token,
            'user' => [
                /** @var int */
                'id' => $user->id,
                /** @var string */
                'name' => $user->name,
                /** @var string|null */
                'rut' => $user->rut,
                /** @var string */
                'email' => $user->email,
                /**
                 * Random ERP branch type: `P` primary, `S` secondary; `null` for internal users.
                 *
                 * @var 'P'|'S'|null
                 */
                'branch_type' => $user->branch_type,
                /**
                 * Whether the user can place orders for other branches, so the frontend shows the branch selector.
                 *
                 * @var bool
                 */
                'can_order_for_branches' => $user->canOrderForBranches(),
                /** @var list<string> */
                'roles' => $roles,
                /** @var list<string> */
                'permissions' => $permissions,
            ]
        ];

        $response['extra'] = [
            /** Whether the user still has the default password and should change it. */
            'weak_password' => false,
        ];

        if (Hash::check('password', $user->password)) {
            $response['extra']['weak_password'] = true;
        }

        return response()->json($response);
    }

    /**
     * Check the token
     *
     * Responds 200 while the token is valid, and 401 once it is revoked or its user is deactivated.
     */
    public function checkToken()
    {
        return response()->json(['valid' => true]);
    }

    /**
     * Log out
     *
     * Revokes the token used in the request.
     *
     * @response array{message: "Token deleted"}
     */
    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token deleted'
        ]);
    }
}
