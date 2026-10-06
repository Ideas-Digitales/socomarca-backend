<?php

use App\Enums\BranchType;
use App\Events\EntityEmailIssuesDetected;
use App\Jobs\SyncRandomUsers;
use App\Models\Address;
use App\Models\Municipality;
use App\Models\Region;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Build a Random entity record (GET /web32/entidades) for a primary customer branch.
 */
function randomEntity(array $overrides = []): array
{
    return array_merge([
        'IDMAEEN'    => 2475,
        'KOEN'       => '77528378',
        'RTEN'       => '77528378',
        'SUEN'       => 'CM',
        'TIPOSUC'    => 'P',
        'TIEN'       => 'C',
        'NOKOEN'     => '500 SABORES SPA',
        'SIEN'       => '500 Sabores',
        'EMAIL'      => 'dte_500sabores@idte.cl',
        'EMAILCOMER' => 'dbustos@grupomilsabores.com',
        'FOEN'       => '56911111111',
        'DIEN'       => '',
        'CIEN'       => '',
        'CMEN'       => '',
        'CPOSTAL'    => '',
        'KOLTVEN'    => ['MIL'],
    ], $overrides);
}

/**
 * Mock the Random API so that each argument is returned as a page of entities.
 */
function mockRandomEntities(array ...$pages): void
{
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getEntidadesUsuarios')->andReturn(...[...$pages, []]);
    App::instance(RandomApiService::class, $mock);
}

function runUsersSync(): void
{
    (new SyncRandomUsers())->handle(app(RandomApiService::class));
}

function seedQuinteroMunicipality(): Municipality
{
    $region = Region::factory()->create([
        'code' => '05',
        'name' => 'Valparaíso',
        'random_key' => 'CL/5',
        'random_name' => 'V Region de Valparaiso',
    ]);

    return Municipality::factory()->create([
        'name' => 'Quintero',
        'region_id' => $region->id,
        'random_key' => 'CL/5/QIN',
        'random_name' => 'Quintero',
    ]);
}

beforeEach(function () {
    Log::spy();
});

describe('users', function () {
    it('creates a customer user for a primary branch entity', function () {
        mockRandomEntities([randomEntity([
            'EMAILCOMER' => '  DBustos@GrupoMilSabores.com ',
            'EMAIL'      => 'DTE_500Sabores@idte.cl',
            'KOLTVEN'    => ['MIL', 'LOC'],
        ])]);

        runUsersSync();

        $user = User::where('random_entity_id', 2475)->firstOrFail();

        expect($user->user_code)->toBe('77528378')
            ->and($user->branch_code)->toBe('CM')
            ->and($user->branch_type)->toBe(BranchType::PRIMARY)
            ->and($user->rut)->toBe('77528378')
            ->and($user->name)->toBe('500 SABORES SPA')
            ->and($user->business_name)->toBe('500 Sabores')
            ->and($user->email)->toBe('dbustos@grupomilsabores.com')
            ->and($user->billing_email)->toBe('dte_500sabores@idte.cl')
            ->and($user->phone)->toBe('56911111111')
            ->and($user->random_user_type)->toBe('C')
            ->and($user->prices_lists)->toBe(['MIL', 'LOC'])
            ->and($user->is_active)->toBeTrue()
            ->and($user->password)->toBeNull()
            ->and($user->random_synced_at)->not->toBeNull()
            ->and($user->hasRole('customer'))->toBeTrue();
    });

    it('creates a user for each branch of the same entity', function () {
        mockRandomEntities([
            randomEntity(['IDMAEEN' => 2475, 'SUEN' => 'CM', 'TIPOSUC' => 'P', 'EMAILCOMER' => 'principal@example.com']),
            randomEntity(['IDMAEEN' => 2476, 'SUEN' => 'LO', 'TIPOSUC' => 'S', 'EMAILCOMER' => 'secundaria@example.com']),
        ]);

        runUsersSync();

        expect(User::where('user_code', '77528378')->count())->toBe(2);

        $secondary = User::where('random_entity_id', 2476)->firstOrFail();
        expect($secondary->branch_code)->toBe('LO')
            ->and($secondary->branch_type)->toBe(BranchType::SECONDARY)
            ->and($secondary->email)->toBe('secundaria@example.com')
            ->and($secondary->hasRole('customer'))->toBeTrue();
    });

    it('syncs entities of type "Ambos"', function () {
        mockRandomEntities([randomEntity(['TIEN' => 'A'])]);

        runUsersSync();

        expect(User::where('random_entity_id', 2475)->value('random_user_type'))->toBe('A');
    });

    it('skips provider entities', function () {
        mockRandomEntities([randomEntity(['TIEN' => 'P'])]);

        runUsersSync();

        expect(User::where('random_entity_id', 2475)->exists())->toBeFalse();
    });

    it('skips entities without IDMAEEN or KOEN', function (array $overrides) {
        mockRandomEntities([randomEntity($overrides)]);

        runUsersSync();

        expect(User::where('user_code', '77528378')->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'missing IDMAEEN or KOEN'))
            ->once();
    })->with([
        'missing IDMAEEN' => [['IDMAEEN' => null]],
        'missing KOEN' => [['KOEN' => '']],
        'blank KOEN' => [['KOEN' => '   ']],
    ]);

    it('normalizes a null SUEN to an empty string', function () {
        mockRandomEntities([randomEntity(['SUEN' => null])]);

        runUsersSync();

        expect(User::where('random_entity_id', 2475)->value('branch_code'))->toBe('');
    });

    it('stores a null email when EMAILCOMER is empty', function () {
        mockRandomEntities([randomEntity(['EMAILCOMER' => '  '])]);

        runUsersSync();

        expect(User::where('random_entity_id', 2475)->firstOrFail()->email)->toBeNull();
    });

    it('syncs entities sharing the same EMAILCOMER', function () {
        mockRandomEntities([
            randomEntity(['IDMAEEN' => 2475, 'SUEN' => 'CM', 'EMAILCOMER' => 'compartido@example.com']),
            randomEntity(['IDMAEEN' => 2476, 'SUEN' => 'LO', 'TIPOSUC' => 'S', 'EMAILCOMER' => 'Compartido@Example.com']),
        ]);

        runUsersSync();

        expect(User::where('email', 'compartido@example.com')->count())->toBe(2);
    });

    it('updates an existing user by IDMAEEN without changing its password or reactivating it', function () {
        $user = User::factory()->create([
            'random_entity_id' => 2475,
            'user_code' => '77528378',
            'branch_code' => 'CM',
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => Hash::make('secret'),
            'is_active' => false,
        ]);
        $passwordHash = $user->password;

        mockRandomEntities([randomEntity(['NOKOEN' => 'New Name', 'SUEN' => 'NEW', 'TIPOSUC' => 'S'])]);

        runUsersSync();

        $user->refresh();
        expect(User::where('user_code', '77528378')->count())->toBe(1)
            ->and($user->name)->toBe('New Name')
            ->and($user->branch_code)->toBe('NEW')
            ->and($user->branch_type)->toBe(BranchType::SECONDARY)
            ->and($user->email)->toBe('dbustos@grupomilsabores.com')
            ->and($user->password)->toBe($passwordHash)
            ->and($user->is_active)->toBeFalse();
    });

    it('associates an existing user without IDMAEEN by KOEN and SUEN', function () {
        $user = User::factory()->create([
            'user_code' => '77528378',
            'branch_code' => 'CM',
            'password' => Hash::make('secret'),
        ]);
        $passwordHash = $user->password;

        mockRandomEntities([randomEntity()]);

        runUsersSync();

        $user->refresh();
        expect(User::where('user_code', '77528378')->count())->toBe(1)
            ->and($user->random_entity_id)->toBe(2475)
            ->and($user->email)->toBe('dbustos@grupomilsabores.com')
            ->and($user->password)->toBe($passwordHash);
    });

    it('associates an existing user with a null branch code to an entity without SUEN', function () {
        $user = User::factory()->create(['user_code' => '77528378', 'branch_code' => null]);

        mockRandomEntities([randomEntity(['SUEN' => ''])]);

        runUsersSync();

        expect($user->fresh()->random_entity_id)->toBe(2475)
            ->and(User::where('user_code', '77528378')->count())->toBe(1);
    });

    it('does not associate a user already linked to another entity', function () {
        $linked = User::factory()->create([
            'random_entity_id' => 9999,
            'user_code' => '77528378',
            'branch_code' => 'CM',
        ]);

        mockRandomEntities([randomEntity()]);

        runUsersSync();

        expect($linked->fresh()->random_entity_id)->toBe(9999)
            ->and(User::where('random_entity_id', 2475)->where('id', '!=', $linked->id)->exists())->toBeTrue();
    });
});

describe('pagination and errors', function () {
    it('syncs every page of entities', function () {
        mockRandomEntities(
            [randomEntity(['IDMAEEN' => 1, 'SUEN' => 'A'])],
            [randomEntity(['IDMAEEN' => 2, 'SUEN' => 'B'])],
        );

        runUsersSync();

        expect(User::whereIn('random_entity_id', [1, 2])->count())->toBe(2);
    });

    it('continues with the next entity when one fails', function () {
        mockRandomEntities([
            randomEntity(['IDMAEEN' => 1, 'SUEN' => 'A', 'RTEN' => str_repeat('9', 300)]),
            randomEntity(['IDMAEEN' => 2, 'SUEN' => 'B']),
        ]);

        runUsersSync();

        expect(User::where('random_entity_id', 1)->exists())->toBeFalse()
            ->and(User::where('random_entity_id', 2)->exists())->toBeTrue();
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'failed syncing entity'))
            ->once();
    });

    it('stops when Random returns an invalid response', function () {
        $mock = Mockery::mock(RandomApiService::class);
        $mock->shouldReceive('getEntidadesUsuarios')->once()->andReturn(['message' => 'jwt malformed']);
        App::instance(RandomApiService::class, $mock);

        runUsersSync();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'invalid Random entities response'))
            ->once();
    });

    it('is queued only once at a time', function () {
        Queue::fake();

        SyncRandomUsers::dispatch();
        SyncRandomUsers::dispatch();

        expect(new SyncRandomUsers())->toBeInstanceOf(ShouldBeUnique::class);
        Queue::assertPushed(SyncRandomUsers::class, 1);
    });
});

describe('email issues alert', function () {
    it('dispatches a single event with the duplicated and missing commercial emails', function () {
        Event::fake([EntityEmailIssuesDetected::class]);
        User::factory()->create(['email' => 'compartido@example.com', 'random_entity_id' => null]);

        mockRandomEntities([
            randomEntity(['IDMAEEN' => 3, 'SUEN' => 'C', 'NOKOEN' => 'Tercera', 'TIPOSUC' => 'S', 'EMAILCOMER' => 'Compartido@Example.com']),
            randomEntity(['IDMAEEN' => 1, 'SUEN' => 'A', 'NOKOEN' => 'Principal', 'EMAILCOMER' => 'compartido@example.com']),
            randomEntity(['IDMAEEN' => 2, 'SUEN' => 'B', 'NOKOEN' => 'Sin email', 'TIPOSUC' => 'S', 'EMAILCOMER' => '']),
            randomEntity(['IDMAEEN' => 4, 'SUEN' => 'D', 'NOKOEN' => 'Unica', 'TIPOSUC' => 'S', 'EMAILCOMER' => 'unica@example.com']),
        ]);

        runUsersSync();

        Event::assertDispatchedTimes(EntityEmailIssuesDetected::class, 1);
        Event::assertDispatched(EntityEmailIssuesDetected::class, function (EntityEmailIssuesDetected $event) {
            return $event->duplicatedEmails === [
                ['IDMAEEN' => 1, 'KOEN' => '77528378', 'SUEN' => 'A', 'NOKOEN' => 'Principal', 'EMAILCOMER' => 'compartido@example.com'],
                ['IDMAEEN' => 3, 'KOEN' => '77528378', 'SUEN' => 'C', 'NOKOEN' => 'Tercera', 'EMAILCOMER' => 'compartido@example.com'],
            ] && $event->missingEmails === [
                ['IDMAEEN' => 2, 'KOEN' => '77528378', 'SUEN' => 'B', 'NOKOEN' => 'Sin email', 'EMAILCOMER' => null],
            ];
        });
    });

    it('considers synced users that were not part of the current sync', function () {
        Event::fake([EntityEmailIssuesDetected::class]);
        User::factory()->create([
            'random_entity_id' => 9,
            'user_code' => '11111111',
            'branch_code' => '',
            'name' => 'Anterior',
            'email' => 'dbustos@grupomilsabores.com',
            'is_active' => true,
        ]);

        mockRandomEntities([randomEntity()]);

        runUsersSync();

        Event::assertDispatched(EntityEmailIssuesDetected::class, function (EntityEmailIssuesDetected $event) {
            return array_column($event->duplicatedEmails, 'IDMAEEN') === [9, 2475];
        });
    });

    it('ignores inactive users', function () {
        Event::fake([EntityEmailIssuesDetected::class]);
        User::factory()->create([
            'random_entity_id' => 9,
            'user_code' => '11111111',
            'email' => 'dbustos@grupomilsabores.com',
            'is_active' => false,
        ]);
        User::factory()->create([
            'random_entity_id' => 10,
            'user_code' => '22222222',
            'email' => null,
            'is_active' => false,
        ]);

        mockRandomEntities([randomEntity()]);

        runUsersSync();

        Event::assertNotDispatched(EntityEmailIssuesDetected::class);
    });

    it('does not dispatch the event when there are no email issues', function () {
        Event::fake([EntityEmailIssuesDetected::class]);
        User::factory()->create(['email' => 'dbustos@grupomilsabores.com', 'random_entity_id' => null]);

        mockRandomEntities([randomEntity()]);

        runUsersSync();

        Event::assertNotDispatched(EntityEmailIssuesDetected::class);
    });

    it('does not dispatch the event when the sync is interrupted', function () {
        Event::fake([EntityEmailIssuesDetected::class]);
        User::factory()->create(['random_entity_id' => 9, 'user_code' => '11111111', 'email' => null]);

        $mock = Mockery::mock(RandomApiService::class);
        $mock->shouldReceive('getEntidadesUsuarios')->once()->andReturn(['message' => 'jwt malformed']);
        App::instance(RandomApiService::class, $mock);

        runUsersSync();

        Event::assertNotDispatched(EntityEmailIssuesDetected::class);
    });
});

describe('addresses', function () {
    it('creates a synced shipping address using CL/{CIEN}/{CMEN}', function (string $branchType) {
        $municipality = seedQuinteroMunicipality();

        mockRandomEntities([randomEntity([
            'TIPOSUC' => $branchType,
            'PAEN'    => 'CHI',
            'CIEN'    => '5',
            'CMEN'    => 'QIN',
            'DIEN'    => 'GARRETON ARCE 1698',
            'CPOSTAL' => '2480000',
        ])]);

        runUsersSync();

        $user = User::where('random_entity_id', 2475)->firstOrFail();

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'is_synced' => true,
            'region_id' => $municipality->region_id,
            'municipality_id' => $municipality->id,
            'address_line1' => 'GARRETON ARCE 1698',
            'postal_code' => '2480000',
            'phone' => '56911111111',
            'contact_name' => '500 SABORES SPA',
            'type' => 'shipping',
            'is_default' => false,
        ]);
    })->with([
        'primary branch' => [BranchType::PRIMARY],
        'secondary branch' => [BranchType::SECONDARY],
    ]);

    it('updates the synced address and keeps the addresses created by the user', function () {
        $municipality = seedQuinteroMunicipality();
        $otherMunicipality = Municipality::factory()->create([
            'region_id' => $municipality->region_id,
            'random_key' => 'CL/5/ALG',
            'random_name' => 'Algarrobo',
        ]);
        $user = User::factory()->create(['random_entity_id' => 2475, 'user_code' => '77528378']);
        $synced = Address::factory()->create([
            'user_id' => $user->id,
            'is_synced' => true,
            'municipality_id' => $otherMunicipality->id,
            'address_line1' => 'Old Street 1',
        ]);
        $own = Address::factory()->create([
            'user_id' => $user->id,
            'address_line1' => 'Own Street 2',
        ]);

        mockRandomEntities([randomEntity([
            'CIEN' => '5',
            'CMEN' => 'QIN',
            'DIEN' => 'GARRETON ARCE 1698',
        ])]);

        runUsersSync();

        expect(Address::where('user_id', $user->id)->where('is_synced', true)->count())->toBe(1);
        $this->assertDatabaseHas('addresses', [
            'id' => $synced->id,
            'municipality_id' => $municipality->id,
            'address_line1' => 'GARRETON ARCE 1698',
        ]);
        $this->assertDatabaseHas('addresses', [
            'id' => $own->id,
            'is_synced' => false,
            'address_line1' => 'Own Street 2',
        ]);
    });

    it('skips the address when it cannot be resolved', function (array $overrides, bool $seedMunicipality) {
        if ($seedMunicipality) {
            seedQuinteroMunicipality();
        }

        mockRandomEntities([randomEntity(array_merge([
            'CIEN' => '5',
            'CMEN' => 'QIN',
            'DIEN' => 'GARRETON ARCE 1698',
        ], $overrides))]);

        runUsersSync();

        expect(User::where('random_entity_id', 2475)->exists())->toBeTrue()
            ->and(Address::count())->toBe(0);
    })->with([
        'region not found' => [[], false],
        'municipality not found' => [['CMEN' => 'XXX'], true],
        'missing CIEN' => [['CIEN' => ''], true],
        'missing DIEN' => [['DIEN' => ''], true],
    ]);
});
