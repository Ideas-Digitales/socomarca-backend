<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Laragear\Rut\Facades\Generator as RutGenerator;

function generateUserData() {
    return [
        'name' => fake()->firstName() . ' ' . fake()->lastName(),
        'email' => fake()->email,
        'password' => fake()->password(10, 12),
        'phone' => strval(fake()->numberBetween(1000000000, 2000000000)),
        'rut' => RutGenerator::makeOne()->formatBasic(),
        'business_name' => fake()->company(),
        'is_active' => true,
    ];
}

function createPermissions(array $permissions) {
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
}

describe('Users read endpoint', function() {
    it('should return a paginated normal users list', function () {
        $permissions = ['read-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            $user->assignRole('customer');
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'phone',
                        'rut',
                        'business_name',
                        'is_active',
                        'roles'
                    ]
                ],
                'links',
                'meta'
            ]);
    });

    it('should return a paginated users list without admin users when having \'read-users\' permissions only', function () {
        $permissions = ['read-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        // Create normal users
        $normalUsers = User::factory()->count(2)->create();
        foreach ($normalUsers as $user) {
            $user->assignRole('customer');
        }

        // Create admin users
        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');
        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('superadmin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('id')->toArray();
//        dd([
//            'userIds' => $userIds,
//            'adminUser' => $adminUser->id,
//            'superadminUser' => $superadminUser->id,
//        ]);

        // Verify admin users are not included in the response
        expect($userIds)->not->toContain($adminUser->id)
            ->and($userIds)->not->toContain($superadminUser->id);
    });

    it('should return a paginated users list with all users when having \'read-admin-users\' and \'read-users\' permissions', function () {
        $permissions = ['read-users', 'read-admin-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        // Create normal users
        $normalUsers = User::factory()->count(2)->create();
        foreach ($normalUsers as $user) {
            $user->assignRole('customer');
        }

        // Create admin users
        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');
        $superadminUser = User::factory()->create();
        $superadminUser->assignRole('superadmin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(200);

        // Verify all users (including admin users) are included in the response
        $userIds = collect($response->json('data'))->pluck('id')->toArray();
        expect($userIds)->toContain($adminUser->id)
            ->and($userIds)->toContain($superadminUser->id);
    });
});

describe('User show endpoint', function () {
    it('should respond 404 when requesting inexistent users', function () {
        $permissions = ['read-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        User::factory()->count(3)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users/99999')
            ->assertStatus(404);
    });

    it('should read a normal user when having the \'read-users\' permission', function () {
        $permissions = ['read-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$user->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'phone',
                'rut',
                'business_name',
                'is_active',
                'roles'
            ]);
    });

    it('should read an admin user when having the \'read-admin-users\' permission', function () {
        $permissions = ['read-admin-users'];
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$adminUser->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'phone',
                'rut',
                'business_name',
                'is_active',
                'roles'
            ]);
    });
});

describe('User creation endpoint', function () {
    it('should perform a validation error when using the same email twice', function () {
        $permissions = ['create-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        $existingUser = User::factory()->create();
        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['email'] = $existingUser->email;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('should perform a validation error when using an invalid rut', function () {
        $permissions = ['create-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $existingUser = User::factory()->create();
        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['rut'] = $existingUser->rut;
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rut']);
    });

    it('should deny user creation without \'create-users\' permission', function () {
        $user = User::factory()->create();
        $userData = generateUserData();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertForbidden();
    });

    it('should perform admin|superadmin user creation when having the permissions to create an admin user', function () {
        \Illuminate\Support\Facades\Notification::fake();
        $permissions = ['create-admin-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $admin->refresh();

        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['roles'] = ['admin'];
        $userData2 = generateUserData();
        $userData2['password_confirmation'] = $userData2['password'];
        $userData2['roles'] = ['superadmin'];

        $this->actingAs($admin, 'sanctum')
            ->postJson(route('users.store'), $userData)
            ->assertCreated();
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson(route('users.store'), $userData2)
            ->assertCreated();

        $adminCreated = User::where('email', $userData['email'])->first();
        $superadminCreated = User::where('email', $userData2['email'])->first();
        expect($adminCreated->hasRole('admin'))->toBeTrue()
            ->and($adminCreated->name == $userData['name'])->toBeTrue()
            ->and($adminCreated->rut == $userData['rut'])->toBeTrue()
            ->and($superadminCreated->name == $userData2['name'])->toBeTrue()
            ->and($superadminCreated->rut == $userData2['rut'])->toBeTrue();
        $userId = $response->json('user.id');
        $user = User::findOrFail($userId);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\UserSavedNotification::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\UserPasswordUpdateNotification::class);
    });

    it('should fail with validation error when giving a wrong password confirmation', function () {
        $permissions = ['create-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        $userData = generateUserData();
        $userData['password_confirmation'] = 'different_password';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('should fail with validation error when giving a wrong email', function () {
        $permissions = ['create-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['email'] = 'invalid-email-format';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('should deny admin user creation when having only \'create-users\' permission without \'create-admin-users\'', function () {
        $permissions = ['create-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['roles'] = ['admin'];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertForbidden();
    });

    it('should successfully create regular user when having \'create-users\' permission', function () {
        \Illuminate\Support\Facades\Notification::fake();
        $permissions = ['create-users'];
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        $userData = generateUserData();
        $userData['password_confirmation'] = $userData['password'];
        $userData['roles'] = ['customer'];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $userData)
            ->assertCreated();

        $createdUser = User::where('email', $userData['email'])->first();
        expect($createdUser->hasRole('customer'))->toBeTrue()
            ->and($createdUser->name)->toBe($userData['name'])
            ->and($createdUser->rut)->toBe($userData['rut']);
    });
});

describe('User update endpoint', function () {
    it('should perform a user (of any role) update when having the permissions: \'update-users\', \'read-users\', \'read-admin-users\'', function () {
        Mail::fake();
        $permissions = ['update-users', 'read-users', 'read-admin-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $admin->refresh();
        $roles = ['superadmin', 'admin', 'supervisor', 'editor', 'customer'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $userData = generateUserData();
            $user = User::factory()->create($userData);
            $user->assignRole($role);

            $updateData = [
                'name' => fake()->firstName() . ' UserControllerTest.php' . fake()->lastName(),
                'roles' => [$role]
            ];

            $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/users/{$user->id}", $updateData)
                ->assertStatus(200);

            $user->refresh();
            expect($user->name)->toBe($updateData['name']);
            //        expect($user->hasRole('admin'))->toBeTrue();
        }
    });

    it('should send the temporary password email after password update', function() {
        $this->freezeTime(function (\Illuminate\Support\Carbon $time) {
            \Illuminate\Support\Facades\Notification::fake();
            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
            $permissions = ['update-users', 'read-users', 'read-admin-users'];
            createPermissions($permissions);
            $admin = User::factory()->create();
            $admin->givePermissionTo($permissions);
            $admin->refresh();
            $user = User::factory()->create();
            $user->assignRole('customer');
            $password = fake()->password(10, 12);
            $payload = [
                'password' => $password,
                'password_confirmation' => $password,
            ];

            $response = $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/users/{$user->id}", $payload);

            $response
                ->assertSuccessful()
                ->assertJson(fn(\Illuminate\Testing\Fluent\AssertableJson $json) => $json->has('user.password_changed_at')
                    ->where('password_changed', true)
                    ->etc()
                );

            \Illuminate\Support\Facades\Notification::assertSentTo(
                $user,
                function (\App\Notifications\UserPasswordUpdateNotification $notification) use ($password) {
                    return $notification->temporaryPassword === $password;
                }
            );

            $user->refresh();
            expect($user->password_changed_at)->toBe($time->toDateTimeString());
        });
    });

    it('shouldn\'t perform an admin user update when having the permissions \'update-users\' and \'read-users\' without \'read-admin-users\'', function () {
        $permissions = ['update-users', 'read-users'];
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $admin->refresh();

        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $updateData = [
            'name' => fake()->firstName() . ' UpdateTest ' . fake()->lastName(),
        ];

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/users/{$adminUser->id}", $updateData)
            ->assertForbidden();
    });

//    it('should fail when payload is incomplete during a full update', function() {
//        $admin = User::factory()->create();
//        $admin->assignRole('admin');
//
//        $user = User::factory()->create();
//        $user->assignRole('cliente');
//
//        $payload = [
//            'name' => fake()->name,
//        ];
//
//        $this->actingAs($admin, 'sanctum')
//            ->putJson("/api/users/{$user->id}", $payload)
//            ->assertInvalid(['email', 'phone', 'is_active', 'password', 'roles']);
//    });
});

describe('User deletion endpoint', function () {

    it('should perform a user (of any role) deletion when having the permissions: \'delete-users\', \'read-users\', \'read-admin-users\'', function () {
        Mail::fake();
        $permissions = ['delete-users', 'read-admin-users', 'read-users'];
        createPermissions($permissions);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $admin->refresh();
        $roles = ['superadmin', 'admin', 'supervisor', 'editor', 'customer'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $userData = generateUserData();
            $user = User::factory()->create($userData);
            $user->assignRole($role);

            $this->actingAs($admin, 'sanctum')
                ->deleteJson("/api/users/{$user->id}")
                ->assertStatus(200);

            $this->assertDatabaseMissing('users', [
                'id' => $user->id
            ]);
        }
    });

    it('should deny an admin user deletion when the user is trying to delete itself', function () {
        $permissions = ['delete-users', 'read-admin-users', 'read-users'];
        createPermissions($permissions);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $admin->givePermissionTo($permissions);
        $admin->refresh();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/users/{$admin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id
        ]);
    });

    it('should deny an admin user deletion when the user has \'delete-users\' permission but without having \'read-admin-users\' permission', function () {
        $permissions = ['delete-users', 'read-users'];
        createPermissions($permissions);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);
        $admin->refresh();

        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/users/{$adminUser->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $adminUser->id
        ]);
    });
});

//test('puede buscar usuarios con filtros', function () {
//    // Arrange
//    $admin = User::factory()->create();
//    $admin->givePermissionTo('manage-users');
//
//    User::factory()->create(['name' => 'Juan Pérez']);
//    User::factory()->create(['name' => 'María García']);
//    User::factory()->create(['name' => 'Carlos López']);
//
//    // Act
//    $response = $this->actingAs($admin, 'sanctum')
//        ->postJson('/api/users/search', [
//            'filters' => [
//                [
//                    'field' => 'name',
//                    'operator' => 'ILIKE',
//                    'value' => '%Juan%'
//                ]
//            ]
//        ]);
//
//    // Assert
//    $response->assertStatus(200)
//        ->assertJsonStructure([
//            'data' => [
//                '*' => [
//                    'id',
//                    'name',
//                    'email',
//                    'roles'
//                ]
//            ],
//            'links',
//            'meta'
//        ]);
//});
