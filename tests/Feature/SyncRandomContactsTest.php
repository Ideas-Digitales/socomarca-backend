<?php

use App\Enums\BranchType;
use App\Jobs\SyncRandomContacts;
use App\Models\Contact;
use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

function randomContactRow(array $overrides = []): array
{
    return array_merge([
        'KOEN' => '77722167',
        'RUTCONTACT' => '86027191-5',
        'SUTRABCON' => 'L01',
        'NOKOCON' => 'Samuel Alderete Rayún',
        'FONOCON' => '+5215656780748',
        'FAXCON' => null,
        'EMAILCON' => 'Salderete@IdeasDigitales.cl',
        'CARGOCON' => null,
        'AREACON' => null,
        'AUTORIZADO' => true,
        'DIRECON' => 'San Francisco 22, Los Héroes Coacalco',
    ], $overrides);
}

function mockRandomContacts(array $byEntity): void
{
    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getContacts')
        ->andReturnUsing(function (string $entityCode) use ($byEntity) {
            return $byEntity[$entityCode] ?? ['data' => [], 'object' => 'list'];
        });
    App::instance(RandomApiService::class, $mock);
}

beforeEach(function () {
    Log::spy();
});

it('creates and updates local contacts from Random', function () {
    $branch = User::factory()->create(['user_code' => '77722167', 'branch_code' => 'L01']);
    Contact::factory()->create([
        'entity_code' => '77722167',
        'rut' => '86027191-5',
        'name' => 'Nombre viejo',
        'random_synced_at' => now()->subDay(),
    ]);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [randomContactRow()],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    $contact = Contact::query()->where('entity_code', '77722167')->where('rut', '86027191-5')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->user_id)->toBe($branch->id)
        ->and($contact->name)->toBe('Samuel Alderete Rayún')
        ->and($contact->email)->toBe('salderete@ideasdigitales.cl')
        ->and($contact->phone)->toBe('+5215656780748')
        ->and($contact->authorized)->toBeTrue()
        ->and($contact->address)->toBe('San Francisco 22, Los Héroes Coacalco')
        ->and(Contact::query()->where('entity_code', '77722167')->count())->toBe(1);
});

it('removes contacts that Random no longer returns', function () {
    User::factory()->create(['user_code' => '77722167']);
    Contact::factory()->create([
        'entity_code' => '77722167',
        'rut' => '11111111-1',
        'random_synced_at' => now()->subDay(),
    ]);

    mockRandomContacts([
        '77722167' => ['object' => 'list', 'data' => []],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->where('entity_code', '77722167')->count())->toBe(0);
});

it('keeps a contact written during the sync when Random has not returned it yet', function () {
    User::factory()->create(['user_code' => '77722167']);
    $fresh = Contact::factory()->create([
        'entity_code' => '77722167',
        'random_synced_at' => now()->addMinute(),
    ]);

    mockRandomContacts([
        '77722167' => ['object' => 'list', 'data' => []],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('does not delete local contacts when Random responds with an invalid payload', function () {
    User::factory()->create(['user_code' => '77722167']);
    $contact = Contact::factory()->create([
        'entity_code' => '77722167',
        'random_synced_at' => now()->subDay(),
    ]);

    mockRandomContacts([
        '77722167' => ['message' => 'error'],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->whereKey($contact->id)->exists())->toBeTrue();
});

it('queries each entity once', function () {
    User::factory()->create(['user_code' => '77722167', 'branch_code' => '1']);
    User::factory()->create(['user_code' => '77722167', 'branch_code' => 'L01']);
    User::factory()->create(['user_code' => '11111111', 'branch_code' => '2']);

    $mock = Mockery::mock(RandomApiService::class);
    $mock->shouldReceive('getContacts')
        ->once()
        ->with('77722167')
        ->andReturn(['object' => 'list', 'data' => []]);
    $mock->shouldReceive('getContacts')
        ->once()
        ->with('11111111')
        ->andReturn(['object' => 'list', 'data' => []]);
    App::instance(RandomApiService::class, $mock);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));
});

it('assigns the contact to the branch whose code equals SUTRABCON', function () {
    User::factory()->create([
        'user_code' => '77722167',
        'branch_code' => 'CM',
        'branch_type' => BranchType::PRIMARY,
    ]);
    $branch = User::factory()->create([
        'user_code' => '77722167',
        'branch_code' => 'L01',
        'branch_type' => BranchType::SECONDARY,
    ]);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [randomContactRow(['SUTRABCON' => ' L01 '])],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->where('rut', '86027191-5')->value('user_id'))->toBe($branch->id);
});

it('moves an existing contact to the branch Random reports', function () {
    $from = User::factory()->create(['user_code' => '77722167', 'branch_code' => 'CM']);
    $to = User::factory()->create(['user_code' => '77722167', 'branch_code' => 'L01']);
    $contact = Contact::factory()->create([
        'user_id' => $from->id,
        'entity_code' => '77722167',
        'rut' => '86027191-5',
        'random_synced_at' => now()->subDay(),
    ]);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [randomContactRow()],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect($contact->fresh()->user_id)->toBe($to->id);
});

it('syncs a contact when SUTRABCON matches a branch code longer than three characters', function () {
    $user = User::factory()->create([
        'user_code' => '77722167',
        'branch_code' => 'VALPO',
    ]);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [randomContactRow(['SUTRABCON' => 'VALPO'])],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->where('rut', '86027191-5')->value('user_id'))->toBe($user->id);
});

it('keeps syncing the other contacts when one branch code cannot be processed', function () {
    User::factory()->create(['user_code' => '77722167', 'branch_code' => 'L01']);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [
                randomContactRow(['RUTCONTACT' => '11111111-1', 'SUTRABCON' => '']),
                randomContactRow(),
            ],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    expect(Contact::query()->where('rut', '11111111-1')->exists())->toBeFalse()
        ->and(Contact::query()->where('rut', '86027191-5')->exists())->toBeTrue();
});

it('does not sync a contact whose branch code SCR cannot process', function (array $branchCodes, ?string $sutrabcon, string $reason) {
    foreach ($branchCodes as $code) {
        User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => $code,
        ]);
    }

    $local = Contact::factory()->create([
        'entity_code' => '77722167',
        'rut' => '86027191-5',
        'name' => 'Nombre local',
        'random_synced_at' => now()->subDay(),
    ]);
    $stale = Contact::factory()->create([
        'entity_code' => '77722167',
        'random_synced_at' => now()->subDay(),
    ]);

    mockRandomContacts([
        '77722167' => [
            'object' => 'list',
            'data' => [randomContactRow(['SUTRABCON' => $sutrabcon])],
        ],
    ]);

    (new SyncRandomContacts)->handle(app(RandomApiService::class));

    $contact = Contact::query()->where('rut', '86027191-5')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->id)->toBe($local->id)
        ->and($contact->name)->toBe('Nombre local')
        ->and(Contact::query()->whereKey($stale->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($reason, $sutrabcon) {
            return $message === 'El contacto no trae un código de sucursal que SCR pueda procesar.'
                && $context['entity_code'] === '77722167'
                && $context['rut'] === '86027191-5'
                && $context['branch_code'] === trim((string) $sutrabcon)
                && $context['reason'] === $reason;
        })
        ->once();
})->with([
    'missing' => [['L01'], null, 'missing'],
    'unknown' => [['L01'], 'ZZZ', 'no_match'],
    'ambiguous' => [['L01', 'L01'], 'L01', 'ambiguous'],
    'truncated longer code' => [['VALPO'], 'VAL', 'no_match'],
]);
