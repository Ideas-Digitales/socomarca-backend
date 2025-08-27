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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{
    public function __construct(private UserService $service) {}

    public function index(Request $request)
    {
        $perPage = request()->input('per_page', 20);
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $users = $this->service->getPaginatedUsers($sort, $sortDirection, $perPage);
        return new UserCollection($users);
    }

    /**
     * Store a newly created user in storage.
     * Requires manage-users permission.
     *
     * @param StoreRequest $storeRequest
     * @return JsonResponse
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

            // Asignar roles si se proporcionan
            if (isset($data['roles']) && is_array($data['roles'])) {
                $user->assignRole($data['roles']);
            } else {
                // Asignar rol por defecto 'cliente' si no se especifica
                $user->assignRole('cliente');
            }

            DB::commit();
            $event = new UserSaved(user: $user, password: $password, action: 'created');
            event($event);

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => new UserResource($user->load('roles')),
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

    public function show(User $user)
    {
        return response()->json(new UserResource($user));
    }

    /**
     * Update the specified user in storage.
     * Requires manage-users permission.
     *
     * @param UpdateRequest $request
     * @param int $id
     * @return JsonResponse
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
     * Remove the specified user from storage.
     * Requires manage-users permission.
     *
     * @param User $user
     */
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

    public function profile(Request $request)
    {
        $user = $request->user();
        return $user->toResource(ProfileResource::class);
        // return $user->toResource();
    }

    /**
     * Search users by filters
     * Requires manage-users permission
     *
     * @param Request $request
     *
     * @return UserCollection
     */
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
                ->paginate($perPage, ['*'], $role.'_page')
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

    public function customersList()
    {
        $clientes = User:: //::role('cliente')
            select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'customer' => $user->name,
                ];
            });

        return response()->json($clientes);
    }

    public function export(Request $request)
    {
        $sort = $request->input('sort', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $fileName = 'Lista_usuarios' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new UsersExport($sort, $sortDirection), $fileName);
    }
}
