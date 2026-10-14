<?php

namespace App\Http\Controllers\Api;

use App\Events\UserSaved;
use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreRequest;
use App\Http\Requests\Users\UpdateRequest;
use App\Http\Resources\Users\ProfileResource;
use App\Http\Resources\Users\UserCollection;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Data\UserService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

#[Group('Users', 'Manage users (internal staff and customers synced from Random ERP) and the authenticated user\'s profile.', weight: 1)]
class UserController extends Controller
{
    public function __construct(
        private UserService $service
    ) {}

    /**
     * List users
     *
     * Users with `read-users` but without `read-admin-users` don't see `admin` and `superadmin` users.
     *
     * @response UserCollection<\Illuminate\Pagination\LengthAwarePaginator<int, User>>
     */
    #[QueryParameter('per_page', 'Users per page.', type: 'int', default: 20)]
    #[QueryParameter('sort', 'User column to sort by.', type: 'string', default: 'name', example: 'created_at')]
    #[QueryParameter('sort_direction', 'Sort direction.', type: "'asc'|'desc'", default: 'asc')]
    public function index(Request $request)
    {
        $perPage = request()->input('per_page', 20);
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $users = $this->service->getPaginatedUsers($sort, $sortDirection, $perPage);
        return new UserCollection($users);
    }

    /**
     * Create a user
     *
     * Creates an internal user and emails it a welcome notification and its password. Assigning the
     * `admin` or `superadmin` role requires the `create-admin-users` permission; other roles require
     * `create-users`. The `customer` role cannot be assigned: customers are synced from Random ERP.
     */
    public function store(StoreRequest $storeRequest): JsonResponse
    {
        try {
            DB::beginTransaction();

            $data = $storeRequest->validated();

            // Generar contraseña si no se proporciona
            $password = $data['password'] ?? Str::random(12);
            $isPasswordGenerated = !isset($data['password']);

            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = Hash::make($password);
            $user->phone = $data['phone'];
            $user->rut = $data['rut'];
            $user->business_name = $data['business_name'];
            $user->is_active = $data['is_active'];
            $user->save();

            $user->assignRole($data['roles']);

            DB::commit();
            $event = new UserSaved(user: $user, password: $password, action: 'created');
            event($event);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => new UserResource($user->load('roles')),
                /** Whether the server generated the password. Always `false` while `password` is required. */
                'password_generated' => $isPasswordGenerated
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creando usuario: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : 'No se pudo crear el usuario'
            ], 500);
        }
    }

    /**
     * Show a user
     *
     * `admin` and `superadmin` users require `read-admin-users`; other users require `read-users`.
     */
    public function show(User $user)
    {
        return response()->json(new UserResource($user));
    }

    /**
     * Update a user
     *
     * `PUT` requires every field (including `password`) and `PATCH` only the sent ones. Users can always
     * update themselves; updating others requires `update-users` and being able to view them.
     *
     * Users synced from Random ERP (`is_synced`) only accept `is_active` and `fcm_token`; their other
     * fields are prohibited (422). Roles are replaced only when `roles` is not empty.
     *
     * Every update emails the user a notification, and changing the password also emails the new
     * password. Deactivating a user revokes its tokens.
     */
    public function update(User $user, UpdateRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();

            $oldFcm = $user->fcm_token ?? null;

            $newPassword = null;

            if ($request->has('password')) {
                $newPassword = $data['password'];
                $data['password'] = Hash::make($data['password']);
                $data['password_changed_at'] = now();
            }

            $roles = $data['roles'] ?? [];
            unset($data['roles']);
            $user->update($data);

            if (!empty($roles)) {
                $user->syncRoles($roles);
            }

            DB::commit();
            $event = new UserSaved(user: $user, password: $newPassword, action: 'updated');
            event($event);

            return response()->json([
                'message' => 'User updated successfully',
                'user' => new UserResource($user->load('roles')),
                /** Whether the request changed the password. */
                'password_changed' => $newPassword !== null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error actualizando usuario: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : 'No se pudo actualizar el usuario'
            ], 500);
        }
    }

    /**
     * Delete a user
     *
     * Requires `delete-users` and being able to view the user; users cannot delete themselves. Users
     * with cart items or favorite lists cannot be deleted (422).
     */
    #[Response(200, 'The user was deleted; the body is empty', mediaType: 'text/html')]
    public function destroy(User $user)
    {
        // Verificar si el usuario tiene pedidos o datos críticos
        if ($user->cartItems()->exists() || $user->favoritesList()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el usuario porque tiene datos asociados (carrito, listas de favoritos, etc.).',
            ], 422);
        }
        $user->cartItems()->delete();
        $user->favoritesList()->delete();
        $user->delete();
    }

    /**
     * Show the profile
     *
     * Profile of the authenticated user. The response is not wrapped in `data`.
     */
    #[Response(200, type: 'Illuminate\Http\JsonResponse<App\Http\Resources\Users\ProfileResource, 200>')]
    public function profile(Request $request)
    {
        $user = $request->user();
        return $user->toResource(ProfileResource::class);
        // return $user->toResource();
    }

    /**
     * Search users
     *
     * Filters users by field conditions and roles. Filters on unsupported fields are ignored. Allowed
     * fields and operators:
     *
     * - `name`, `business_name`: `=`, `!=`, `LIKE`, `ILIKE`, `NOT LIKE`, `fulltext` (trigram similarity,
     *   results sorted by similarity).
     * - `email`, `rut`, `phone`: `=`, `!=`, `LIKE`, `ILIKE`, `NOT LIKE`.
     * - `is_active`: `=`, `!=`.
     * - `name_or_email`: matches `name` or `email`; `LIKE`/`ILIKE` (default `ILIKE`) match anywhere in the value.
     *
     * For the other fields, `LIKE`-style operators don't add wildcards: include `%` in `value`.
     */
    #[BodyParameter('filters', 'Field conditions, all of which must match. `operator` defaults to `=`; `sort` (`ASC`/`DESC`) also sorts by the field.', type: 'list<array{field: string, operator?: string, value: mixed, sort?: \'ASC\'|\'DESC\'}>', example: [['field' => 'name_or_email', 'operator' => 'ILIKE', 'value' => 'juan']])]
    #[BodyParameter('roles', 'Only users with any of these roles.', type: 'list<string>', example: ['admin', 'supervisor'])]
    #[BodyParameter('sort_field', 'User column to sort by.', type: 'string', default: 'name')]
    #[BodyParameter('sort_direction', 'Sort direction.', type: "'asc'|'desc'", default: 'asc')]
    #[BodyParameter('per_page', 'Users per page.', type: 'int', default: 20)]
    public function search(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $filters = $request->input('filters', []);


        $roles = $request->input('roles', []);
        if (!empty($roles)) {
            if (count($roles) === 1) {
                $filters[] = [
                    'field' => 'role',
                    'operator' => '=',
                    'value' => $roles[0],
                ];
            } else {
                $filters[] = [
                    'field' => 'role',
                    'operator' => 'IN',
                    'value' => $roles,
                ];
            }
        }

        $sortField = $request->input('sort_field', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');

        $result = User::select("users.*")
            ->with('roles')
            ->filter($filters)
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage);

        return new \App\Http\Resources\Users\UserCollection($result);
    }

    public function searchUsers(Request $request)
    {
        $roles = $request->input('roles', []);
        $sortField = $request->input('sort_field', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $perPage = $request->input('per_page', 20);

        $result = [];

        foreach ($roles as $role) {
            $users = User::role($role)
                ->with('roles')
                ->orderBy($sortField, $sortDirection)
                ->paginate($perPage, ['*'], $role . '_page')
                ->items();

            $result[] = [
                'role' => $role,
                'users' => $users,
            ];
        }

        return response()->json($result);
    }

    /**
     * Check if there are significant changes in user data
     *
     * @param array $originalData
     * @param array $newData
     * @return bool
     */
    private function hasSignificantChanges(array $originalData, array $newData): bool
    {
        $fieldsToCheck = ['name', 'email', 'phone', 'rut', 'business_name', 'is_active'];

        foreach ($fieldsToCheck as $field) {
            if (($originalData[$field] ?? '') !== ($newData[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * List user names
     *
     * ID and name of every user, ordered by name, e.g. for report filters. Despite the path, the list
     * is not limited to customers.
     */
    public function customersList()
    {
        $clientes = User:: //::role('cliente')
            select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    /** User name. */
                    'customer' => $user->name,
                ];
            });

        return response()->json($clientes);
    }

    /**
     * Export users to Excel
     *
     * Downloads an `.xlsx` file with the ID, name, email, phone and active state of the users with the
     * legacy `cliente` role. No role has that name anymore (customers have `customer`), so the file
     * currently only has the headings.
     *
     * @response \Symfony\Component\HttpFoundation\BinaryFileResponse<string, 200, array{"Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}, null>
     */
    #[QueryParameter('sort', 'User column to sort by.', type: 'string', default: 'name')]
    #[QueryParameter('sort_direction', 'Sort direction.', type: "'asc'|'desc'", default: 'asc')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function export(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $fileName = 'Lista_usuarios' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new UsersExport($sort, $sortDirection), $fileName);
    }

    /**
     * Save the FCM token
     *
     * Stores the Firebase Cloud Messaging token of the authenticated user's device, used to send it
     * push notifications. Replaces the previous token.
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        // Valida que el token está presente
        $request->validate([
            /** Firebase Cloud Messaging registration token of the device. */
            'fcm_token' => 'required|string'
        ]);

        $user = $request->user();

        $user->update(['fcm_token' => $request->fcm_token]);

        Log::info('Firebase FCM token updated', ['user_id' => $user->id]);

        return response()->json(['message' => 'FCM Token saved.']);
    }
}
