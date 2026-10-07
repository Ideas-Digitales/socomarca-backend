<?php

use App\DTOs\Password;
use App\Enums\BranchType;
use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\Security\PasswordGeneratorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\instance;
use function Pest\Laravel\postJson;

describe('login', function () {
    it('logs in with a valid email and password', function () {
        $user = User::factory()->create([
            'email' => 'juan@example.cl',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'juan@example.cl',
            'password' => 'password123',
            'device_name' => 'test-device',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'rut', 'email', 'branch_type', 'can_order_for_branches', 'roles', 'permissions'],
                'extra' => ['weak_password'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('extra.missing_email');

        expect($user->fresh()->last_login)->not->toBeNull();
    });

    it('normalizes the email before looking up the user', function () {
        User::factory()->create([
            'email' => 'juan@example.cl',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        postJson(route('auth.token.store'), [
            'email' => '  Juan@Example.CL ',
            'password' => 'password123',
        ])->assertOk();
    });

    it('requires the email and the password', function () {
        postJson(route('auth.token.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });

    it('responds 401 when the password is wrong', function () {
        User::factory()->create([
            'email' => 'juan@example.cl',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'juan@example.cl',
            'password' => 'wrongpassword',
        ])->assertUnauthorized();
    });

    it('responds 401 when the email is not registered', function () {
        postJson(route('auth.token.store'), [
            'email' => 'nobody@example.cl',
            'password' => 'password123',
        ])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized']);
    });

    it('responds 401 when the user is inactive', function () {
        User::factory()->create([
            'email' => 'juan@example.cl',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'juan@example.cl',
            'password' => 'password123',
        ])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized']);
        assertGuest();
    });

    it('responds 401 when the user has no password', function () {
        User::factory()->create([
            'email' => 'juan@example.cl',
            'password' => null,
            'is_active' => true,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'juan@example.cl',
            'password' => 'password',
        ])->assertUnauthorized();
    });

    it('denies the login when the email is assigned to more than one active user', function () {
        createSyncedCustomer([
            'email' => 'compras@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'CM',
        ]);
        createSyncedCustomer([
            'email' => 'compras@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'compras@example.cl',
            'password' => 'password123',
        ])->assertUnauthorized();
    });

    it('ignores inactive users when checking for duplicated emails', function () {
        $user = createSyncedCustomer([
            'email' => 'compras@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'CM',
        ]);
        createSyncedCustomer([
            'email' => 'compras@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => false,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'compras@example.cl',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    });

    it('allows secondary branches to log in', function () {
        createSyncedCustomer(['branch_code' => 'CM']);
        $secondary = createSyncedCustomer([
            'email' => 'sucursal@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
        ]);

        postJson(route('auth.token.store'), [
            'email' => 'sucursal@example.cl',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $secondary->id)
            ->assertJsonPath('user.branch_type', BranchType::SECONDARY)
            ->assertJsonPath('user.can_order_for_branches', false);
    });

    it('lets a primary branch order for its active secondary branches', function (array $secondaries, bool $expected) {
        createSyncedCustomer([
            'email' => 'principal@example.cl',
            'password' => Hash::make('password123'),
            'branch_code' => 'CM',
        ]);

        foreach ($secondaries as $index => $attributes) {
            createSyncedCustomer(array_merge([
                'branch_code' => "S{$index}",
                'branch_type' => BranchType::SECONDARY,
            ], $attributes));
        }

        postJson(route('auth.token.store'), [
            'email' => 'principal@example.cl',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.branch_type', BranchType::PRIMARY)
            ->assertJsonPath('user.can_order_for_branches', $expected);
    })->with([
        'with an active secondary branch' => [[[]], true],
        'without secondary branches' => [[], false],
        'with an inactive secondary branch only' => [[['is_active' => false]], false],
        'with a secondary branch of another entity only' => [[['user_code' => '99999999']], false],
    ]);

    it('returns a null branch type for internal users', function () {
        $admin = User::factory()->create([
            'email' => 'admin@example.cl',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        postJson(route('auth.token.store'), [
            'email' => 'admin@example.cl',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.branch_type', null)
            ->assertJsonPath('user.can_order_for_branches', false);
    });
});

test('User can destroy session', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['api-access']);

    deleteJson(route('auth.token.destroy'))->assertOk();
});

test('Authenticated user can get its own information', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/users/' . $user->id)->assertOk();
});

test('allows a user to log in with the temporary password sent by email', function () {
    Mail::fake();

    $user = createSyncedCustomer([
        'email' => 'juan@example.cl',
        'password' => null,
    ]);
    $newPassword = fake()->password();
    $passwordDto = new Password($newPassword, Hash::make($newPassword));
    instance(
        PasswordGeneratorService::class,
        Mockery::mock(PasswordGeneratorService::class, function (MockInterface $mock) use ($passwordDto) {
            $mock->expects('generate')->andReturn($passwordDto);
        })
    );

    postJson(route('auth.password.restore'), [
        'email' => 'Juan@Example.cl',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', Str::maskEmail('juan@example.cl'));

    Mail::assertQueued(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($user->email));

    $response = postJson(route('auth.token.store'), [
        'email' => 'juan@example.cl',
        'password' => $newPassword,
    ]);
    $response->assertOk();

    auth()->forgetUser(); // IMPORTANT! Reset auth state

    getJson(route('products.index'), ['Authorization' => 'Bearer ' . $response->json('token')])
        ->assertOk();
});
