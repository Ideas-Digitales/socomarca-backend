<?php

use App\Enums\BranchType;
use App\Jobs\SyncRandomContacts;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

function contactPayload(Contact $contact, array $extra = []): array
{
    return array_merge([
        'rut' => $contact->rut,
        'name' => $contact->name,
        'email' => $contact->email,
        'phone' => $contact->phone,
        'authorized' => $contact->authorized,
        'address' => $contact->address,
    ], $extra);
}

function fakeContactUpdate(int $created = 1, int $updated = 0): void
{
    Http::fake([
        '*/web32/contactos' => Http::response([
            'updated' => $updated,
            'created' => $created,
        ], 200),
    ]);
}

describe('Contacts list', function () {
    it('requires authentication', function () {
        getJson(route('contacts.index'))->assertUnauthorized();
    });

    it('lists the contacts of the authenticated user entity without calling Random', function () {
        Http::preventStrayRequests();

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo('read-own-contacts');
        $own = Contact::factory()->create([
            'user_id' => $user->id,
            'entity_code' => '77722167',
            'name' => 'Ana',
        ]);
        $otherBranch = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
        ]);
        Contact::factory()->create([
            'user_id' => $otherBranch->id,
            'entity_code' => '77722167',
            'name' => 'Otra sucursal',
        ]);
        Contact::factory()->create(['entity_code' => '11111111', 'name' => 'Otra']);

        Sanctum::actingAs($user, ['api-access']);

        getJson(route('contacts.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.rut', $own->rut);

        Http::assertNothingSent();
    });

    it('lists every contact for a user who can read all of them', function () {
        $user = User::factory()->create(['user_code' => '77722167', 'is_active' => true]);
        $user->givePermissionTo(['read-own-contacts', 'read-all-contacts']);
        Contact::factory()->count(2)->create();

        Sanctum::actingAs($user, ['api-access']);

        getJson(route('contacts.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('Contacts store', function () {
    it('saves the contact and updates Random in the same request', function () {
        Bus::fake();
        fakeContactUpdate();

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'L01',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo('create-contacts');
        $draft = Contact::factory()->make([
            'entity_code' => '77722167',
            'email' => 'Salderete@IdeasDigitales.cl',
            'address' => 'San Francisco 22, Los Héroes Coacalco',
            'phone' => '+5215656780748',
        ]);

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))
            ->assertCreated()
            ->assertJsonPath('data.entity_code', '77722167')
            ->assertJsonPath('data.rut', $draft->rut)
            ->assertJsonPath('data.email', 'salderete@ideasdigitales.cl')
            ->assertJsonPath('data.name', $draft->name)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.branch.id', $user->id);

        assertDatabaseHas('contacts', [
            'entity_code' => '77722167',
            'rut' => $draft->rut,
            'email' => 'salderete@ideasdigitales.cl',
        ]);

        Http::assertSent(function ($request) use ($draft) {
            $body = $request->data();

            return $request->method() === 'PUT'
                && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/web32/contactos')
                && $body[0]['codigoEntidad'] === '77722167'
                && $body[0]['rutContacto'] === $draft->rut
                && $body[0]['nombreContacto'] === $draft->name
                && $body[0]['emailContacto'] === 'salderete@ideasdigitales.cl'
                && $body[0]['fonoContacto'] === '+5215656780748'
                && $body[0]['SUTRABCON'] === 'L01'
                && $body[0]['AUTORIZADO'] === true
                && $body[0]['DIRECON'] === 'San Francisco 22, Los Héroes Coacalco';
        });

        Bus::assertNotDispatched(SyncRandomContacts::class);
    });

    it('sends an empty branch code when the branch has none', function () {
        fakeContactUpdate();

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => null,
            'is_active' => true,
        ]);
        $user->givePermissionTo('create-contacts');
        $draft = Contact::factory()->make();

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))->assertCreated();

        Http::assertSent(fn ($request) => $request->data()[0]['SUTRABCON'] === '');
    });

    it('does not save the contact when Random rejects it', function () {
        Http::fake([
            '*/web32/contactos' => Http::response(['message' => 'error'], 500),
        ]);

        $user = User::factory()->create(['user_code' => '77722167', 'is_active' => true]);
        $user->givePermissionTo('create-contacts');
        $draft = Contact::factory()->make();

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))->assertStatus(500);

        assertDatabaseMissing('contacts', ['rut' => $draft->rut]);
    });

    it('rejects a duplicated rut in the same entity', function () {
        $user = User::factory()->create(['user_code' => '77722167', 'is_active' => true]);
        $user->givePermissionTo('create-contacts');
        $existing = Contact::factory()->create(['entity_code' => '77722167']);

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($existing))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rut');
    });

    it('rejects an address longer than 50 characters', function () {
        $user = User::factory()->create(['user_code' => '77722167', 'is_active' => true]);
        $user->givePermissionTo('create-contacts');
        $draft = Contact::factory()->make(['address' => str_repeat('a', 51)]);

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
    });
});

describe('Contacts update', function () {
    it('updates the contact locally and in Random in the same request', function () {
        Bus::fake();
        fakeContactUpdate(0, 1);

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => '1',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'entity_code' => '77722167',
            'name' => 'Nombre anterior',
        ]);

        Sanctum::actingAs($user, ['api-access']);

        putJson(route('contacts.update', $contact), [
            'name' => 'Samuel Alderete Rayún',
            'email' => $contact->email,
            'phone' => $contact->phone,
            'authorized' => true,
            'address' => 'Calle Nueva 12',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Samuel Alderete Rayún')
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.rut', $contact->rut);

        assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'name' => 'Samuel Alderete Rayún',
            'user_id' => $user->id,
        ]);

        Http::assertSent(function ($request) use ($contact) {
            $body = $request->data();

            return $request->method() === 'PUT'
                && $body[0]['rutContacto'] === $contact->rut
                && $body[0]['nombreContacto'] === 'Samuel Alderete Rayún'
                && $body[0]['SUTRABCON'] === '1'
                && $body[0]['DIRECON'] === 'Calle Nueva 12';
        });

        Bus::assertNotDispatched(SyncRandomContacts::class);
    });

    it('keeps the previous contact when Random rejects the update', function () {
        Http::fake([
            '*/web32/contactos' => Http::response(['updated' => 0, 'created' => 0], 200),
        ]);

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => '1',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'entity_code' => '77722167',
            'name' => 'Nombre anterior',
        ]);

        Sanctum::actingAs($user, ['api-access']);

        patchJson(route('contacts.update', $contact), ['name' => 'Nombre nuevo'])
            ->assertStatus(500);

        assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'name' => 'Nombre anterior',
        ]);
    });

    it('does not allow changing the rut', function () {
        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => '1',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'entity_code' => '77722167',
        ]);
        $other = Contact::factory()->make();

        Sanctum::actingAs($user, ['api-access']);

        patchJson(route('contacts.update', $contact), ['rut' => $other->rut])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rut');
    });

    it('does not update a contact of another entity', function () {
        $user = User::factory()->create(['user_code' => '77722167', 'is_active' => true]);
        $user->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create(['entity_code' => '11111111']);

        Sanctum::actingAs($user, ['api-access']);

        patchJson(route('contacts.update', $contact), ['name' => 'No'])
            ->assertForbidden();
    });
});

describe('Contact branch authorization', function () {
    it('lets a primary branch see its contacts and those of its secondary branches', function () {
        $primary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
            'is_active' => true,
        ]);
        $secondary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $sibling = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'IN',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $primary->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $secondary->givePermissionTo('read-own-contacts');

        $own = Contact::factory()->create(['user_id' => $primary->id, 'entity_code' => '77722167', 'name' => 'Casa matriz']);
        $ofSecondary = Contact::factory()->create(['user_id' => $secondary->id, 'entity_code' => '77722167', 'name' => 'Local']);
        $ofSibling = Contact::factory()->create(['user_id' => $sibling->id, 'entity_code' => '77722167', 'name' => 'Interior']);
        $foreign = Contact::factory()->create(['entity_code' => '11111111', 'name' => 'Ajena']);

        expect($primary->can('view', $own))->toBeTrue()
            ->and($primary->can('view', $ofSecondary))->toBeTrue()
            ->and($primary->can('view', $ofSibling))->toBeTrue()
            ->and($primary->can('update', $ofSecondary))->toBeTrue()
            ->and($primary->can('view', $foreign))->toBeFalse()
            ->and($secondary->can('view', $ofSecondary))->toBeTrue()
            ->and($secondary->can('view', $own))->toBeFalse()
            ->and($secondary->can('view', $ofSibling))->toBeFalse();

        Sanctum::actingAs($secondary, ['api-access']);

        getJson(route('contacts.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ofSecondary->id);

        Sanctum::actingAs($primary, ['api-access']);

        getJson(route('contacts.index'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('rejects a contact for a branch whose code is longer than three characters', function () {
        Log::spy();
        Http::preventStrayRequests();

        $user = User::factory()->create([
            'user_code' => '9876543-2',
            'branch_code' => 'VALPO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo(['create-contacts', 'read-own-contacts']);
        $draft = Contact::factory()->make();

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_code');

        assertDatabaseMissing('contacts', ['rut' => $draft->rut]);
        Http::assertNothingSent();

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($user) {
                return $message === 'La sucursal tiene un código de más de 3 caracteres.'
                    && $context['user_id'] === $user->id
                    && $context['entity_code'] === '9876543-2'
                    && $context['branch_code'] === 'VALPO';
            })
            ->once();
    });

    it('rejects an update when the branch code is longer than three characters', function () {
        Log::spy();
        Http::preventStrayRequests();

        $user = User::factory()->create([
            'user_code' => '9876543-2',
            'branch_code' => 'VALPO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'entity_code' => '9876543-2',
            'name' => 'Nombre anterior',
        ]);

        Sanctum::actingAs($user, ['api-access']);

        patchJson(route('contacts.update', $contact), ['name' => 'Nombre nuevo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_code');

        assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'name' => 'Nombre anterior',
        ]);
        Http::assertNothingSent();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'La sucursal tiene un código de más de 3 caracteres.'
                && $context['branch_code'] === 'VALPO')
            ->once();
    });

    it('rejects moving a contact to a branch whose code is longer than three characters', function () {
        Log::spy();
        Http::preventStrayRequests();

        $primary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
            'is_active' => true,
        ]);
        $long = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'VALPO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $primary->givePermissionTo(['read-own-contacts', 'update-contacts']);
        $contact = Contact::factory()->create([
            'user_id' => $primary->id,
            'entity_code' => '77722167',
            'name' => 'Casa matriz',
        ]);

        Sanctum::actingAs($primary, ['api-access']);

        patchJson(route('contacts.update', $contact), ['user_id' => $long->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_code');

        assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'user_id' => $primary->id,
            'name' => 'Casa matriz',
        ]);
        Http::assertNothingSent();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $context['user_id'] === $long->id
                && $context['branch_code'] === 'VALPO')
            ->once();
    });

    it('assigns the authenticated user when user_id is omitted', function () {
        fakeContactUpdate();

        $user = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $user->givePermissionTo('create-contacts');
        $draft = Contact::factory()->make();

        Sanctum::actingAs($user, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft))
            ->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.branch.name', $user->name);
    });

    it('lets a primary branch create and update a contact of a secondary branch', function () {
        fakeContactUpdate();

        $primary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
            'is_active' => true,
        ]);
        $secondary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $primary->givePermissionTo(['create-contacts', 'read-own-contacts', 'update-contacts']);
        $draft = Contact::factory()->make();

        Sanctum::actingAs($primary, ['api-access']);

        $created = postJson(route('contacts.store'), contactPayload($draft, ['user_id' => $secondary->id]))
            ->assertCreated()
            ->assertJsonPath('data.user_id', $secondary->id)
            ->assertJsonPath('data.branch.branch_type', BranchType::SECONDARY)
            ->json('data.id');

        putJson(route('contacts.update', $created), [
            'name' => 'Contacto del local',
            'email' => $draft->email,
            'phone' => $draft->phone,
            'authorized' => true,
            'address' => $draft->address,
            'user_id' => $secondary->id,
        ])->assertOk()
            ->assertJsonPath('data.user_id', $secondary->id)
            ->assertJsonPath('data.name', 'Contacto del local');

        expect(Http::recorded()->map(fn (array $pair) => $pair[0]->data()[0]['SUTRABCON'])->all())
            ->toBe(['LO', 'LO']);
    });

    it('rejects a secondary branch that assigns another branch', function () {
        $secondary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'LO',
            'branch_type' => BranchType::SECONDARY,
            'is_active' => true,
        ]);
        $primary = User::factory()->create([
            'user_code' => '77722167',
            'branch_code' => 'CM',
            'branch_type' => BranchType::PRIMARY,
            'is_active' => true,
        ]);
        $secondary->givePermissionTo(['create-contacts', 'read-own-contacts', 'update-contacts']);
        $primaryContact = Contact::factory()->create([
            'user_id' => $primary->id,
            'entity_code' => '77722167',
        ]);
        $draft = Contact::factory()->make();

        Sanctum::actingAs($secondary, ['api-access']);

        postJson(route('contacts.store'), contactPayload($draft, ['user_id' => $primary->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        patchJson(route('contacts.update', $primaryContact), ['name' => 'No'])
            ->assertForbidden();
    });
});
