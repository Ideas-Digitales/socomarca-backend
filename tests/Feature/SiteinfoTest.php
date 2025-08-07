<?php

namespace Tests\Feature;

use App\Models\Siteinfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

// Prepara los roles y permisos necesarios antes de cada test
beforeEach(function () {
    // Crear permisos
    Permission::firstOrCreate(['name' => 'read-content-settings']);
    Permission::firstOrCreate(['name' => 'update-content-settings']);
    Permission::firstOrCreate(['name' => 'read-all-system-config']);
    Permission::firstOrCreate(['name' => 'update-system-config']);
    
    // Crear roles
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    
    // Asignar permisos a roles
    $adminRole = Role::findByName('admin');
    $adminRole->givePermissionTo(['read-content-settings', 'update-content-settings', 'read-all-system-config', 'update-system-config']);
    
    $editorRole = Role::findByName('editor');
    $editorRole->givePermissionTo(['read-content-settings', 'update-content-settings']);
    
    $superadminRole = Role::findByName('superadmin');
    $superadminRole->givePermissionTo(['read-content-settings', 'update-content-settings', 'read-all-system-config', 'update-system-config']);
});

test('show endpoint requires authentication', function () {
    $this->getJson('/api/siteinfo')->assertUnauthorized();
});

test('show endpoint requires read-content-settings permission', function () {
    $user = User::factory()->create(); // User without permission
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/siteinfo')
        ->assertForbidden();
});

test('show endpoint returns default structure when database is empty', function () {
    $user = User::factory()->create()->assignRole('admin');
    
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/siteinfo')
        ->assertOk()
        ->assertJsonStructure([
            'header' => ['contact_phone', 'contact_email'],
            'footer' => ['contact_phone', 'contact_email'],
            'social_media' => [['label', 'link']]
        ]);
});

test('show endpoint returns saved values from database', function () {
    $user = User::factory()->create()->assignRole('admin');
    Siteinfo::create(['key' => 'header', 'value' => ['contact_phone' => '123', 'contact_email' => 'a@b.com']]);
    Siteinfo::create(['key' => 'footer', 'value' => ['contact_phone' => '456', 'contact_email' => 'c@d.com']]);
    Siteinfo::create(['key' => 'social_media', 'value' => [['label' => 'fb', 'link' => 'fb.com']]]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/siteinfo')
        ->assertOk()
        ->assertJson([
            'header' => ['contact_phone' => '123', 'contact_email' => 'a@b.com'],
            'footer' => ['contact_phone' => '456', 'contact_email' => 'c@d.com'],
            'social_media' => [['label' => 'fb', 'link' => 'fb.com']]
        ]);
});

test('update endpoint requires authentication and correct permission', function () {
    $this->putJson('/api/siteinfo', [])->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');
    $this->putJson('/api/siteinfo', [])->assertForbidden();
});

test('an admin can update siteinfo', function () {
    $user = User::factory()->create()->assignRole('admin');

    $payload = [
        'header' => ['contact_phone' => '999', 'contact_email' => 'x@y.com'],
        'footer' => ['contact_phone' => '888', 'contact_email' => 'z@w.com'],
        'social_media' => [['label' => 'ig', 'link' => 'ig.com']]
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/siteinfo', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Siteinfo updated successfully']);

    $this->assertDatabaseHas('siteinfo', ['key' => 'header']);
    $this->assertDatabaseHas('siteinfo', ['key' => 'footer']);
    $this->assertDatabaseHas('siteinfo', ['key' => 'social_media']);
});

test('terms and privacy policy endpoints require authentication', function () {
    $this->getJson('/api/terms')->assertUnauthorized();
    $this->getJson('/api/privacy-policy')->assertUnauthorized();
});

test('terms and privacy policy endpoints require read-content-settings permission', function () {
    $user = User::factory()->create(); // User without permission
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/terms')
        ->assertForbidden();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/privacy-policy')
        ->assertForbidden();
});

test('terms and privacy policy endpoints return correct content', function () {
    $user = User::factory()->create()->assignRole('admin');
    Siteinfo::create(['key' => 'terms', 'content' => '<h1>Terms</h1>']);
    Siteinfo::create(['key' => 'privacy_policy', 'content' => '<h1>Policy</h1>']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/terms')
        ->assertOk()
        ->assertJson(['content' => '<h1>Terms</h1>']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/privacy-policy')
        ->assertOk()
        ->assertJson(['content' => '<h1>Policy</h1>']);
});

test('an editor can update terms and privacy policy', function () {
    $user = User::factory()->create()->assignRole('editor');

    $this->actingAs($user, 'sanctum');

    $this->putJson('/api/terms', ['content' => '<h1>New Terms</h1>'])
        ->assertOk()
        ->assertJson(['message' => 'Terms upadated succesfully']);

    $this->putJson('/api/privacy-policy', ['content' => '<h1>New Policy</h1>'])
        ->assertOk()
        ->assertJson(['message' => 'Privacy Policy updated successfully']);

    $this->assertDatabaseHas('siteinfo', ['key' => 'terms', 'content' => '<h1>New Terms</h1>']);
    $this->assertDatabaseHas('siteinfo', ['key' => 'privacy_policy', 'content' => '<h1>New Policy</h1>']);
});

test('customer message endpoint requires authentication', function () {
    $this->getJson('/api/customer-message')->assertUnauthorized();
});

test('customer message endpoint requires read-content-settings permission', function () {
    $user = User::factory()->create(); // User without permission
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/customer-message')
        ->assertForbidden();
});

test('customer message endpoint returns default structure', function () {
    $user = User::factory()->create()->assignRole('admin');
    
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/customer-message')
        ->assertOk()
        ->assertJsonStructure([
            'header' => ['color', 'content'],
            'banner' => ['desktop_image', 'mobile_image', 'enabled'],
            'modal' => ['image', 'enabled']
        ]);
});

test('a superadmin can update customer message without header_content', function () {
    $user = User::factory()->create()->assignRole('superadmin');

    $payload = [
        'header_color' => '#fff',
        'banner_enabled' => true,
        'modal_enabled' => true,
        'message_enabled' => true,
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/customer-message', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

    $record = Siteinfo::where('key', 'customer_message')->first();
    expect($record)->not->toBeNull();
    expect($record->value['header']['content'])->toBe(''); // Debe ser string vacío
});

test('a superadmin can update customer message with images', function () {
    Storage::fake('public');
    $user = User::factory()->create()->assignRole('superadmin');
    $imagePath = public_path('images/test-image.png');

    if (!file_exists($imagePath)) {
        if (!is_dir(dirname($imagePath))) {
            mkdir(dirname($imagePath), 0755, true);
        }
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    $payload = [
        'header_color' => '#fff',
        'header_content' => '<h1>Hola</h1>',
        'banner_enabled' => true,
        'modal_enabled' => true,
        'message_enabled' => true,
        'banner_desktop_image' => new UploadedFile($imagePath, 'desktop.png', 'image/png', null, true),
        'banner_mobile_image' => new UploadedFile($imagePath, 'mobile.png', 'image/png', null, true),
        'modal_image' => new UploadedFile($imagePath, 'modal.png', 'image/png', null, true),
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/customer-message', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

    $record = Siteinfo::where('key', 'customer_message')->first();
    expect($record)->not->toBeNull();

    expect($record->value['banner']['enabled'])->toBeBool();
    expect($record->value['modal']['enabled'])->toBeBool();
    expect($record->value['message']['enabled'])->toBeBool();
    
    expect($record->value['banner']['desktop_image'])->toStartWith('http');
    expect($record->value['banner']['mobile_image'])->toStartWith('http');
    expect($record->value['modal']['image'])->toStartWith('http');
});

test('a superadmin can update customer message without images', function () {
    $user = User::factory()->create()->assignRole('superadmin');

    $payload = [
        'header_color' => '#fff',
        'header_content' => '<h1>Hola</h1>',
        'banner_enabled' => true,
        'modal_enabled' => true,
        'message_enabled' => true,
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/customer-message', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

    $record = Siteinfo::where('key', 'customer_message')->first();
    expect($record)->not->toBeNull();

    // Verifica que los campos booleanos sean booleanos
    expect($record->value['banner']['enabled'])->toBeBool();
    expect($record->value['modal']['enabled'])->toBeBool();
    expect($record->value['message']['enabled'])->toBeBool();

    // Verifica que las imágenes sean string vacíos (no concatenados)
    expect($record->value['banner']['desktop_image'])->toBe('');
    expect($record->value['banner']['mobile_image'])->toBe('');
    expect($record->value['modal']['image'])->toBe('');
});

// Webpay tests

test('webpay config endpoint requires authentication', function () {
    $this->getJson('/api/webpay/config')->assertUnauthorized();
});

test('webpay config endpoint requires read-all-system-config permission', function () {
    $user = User::factory()->create(); // User without permission
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/webpay/config')
        ->assertForbidden();
});

test('webpay config endpoint returns default structure when database is empty', function () {
    $user = User::factory()->create()->assignRole('admin');
    
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/webpay/config')
        ->assertStatus(404)
        ->assertJson([
            'message' => 'No se encontró la configuración de Webpay',
            'data' => [],
        ]);
});

test('webpay config endpoint returns stored values', function () {
    $user = User::factory()->create()->assignRole('admin');
    $stored = [
        'WEBPAY_COMMERCE_CODE' => '123456',
        'WEBPAY_API_KEY' => 'SOMEKEY',
        'WEBPAY_ENVIRONMENT' => 'production',
        'WEBPAY_RETURN_URL' => 'https://example.com/return',
    ];
    Siteinfo::create([
        'key' => 'WEBPAY_INFO',
        'value' => $stored,
        'content' => 'Informacion de entorno webpay',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/webpay/config')
        ->assertOk()
        ->assertJson($stored);
});

test('update webpay config requires authentication and update-system-config permission', function () {
    $payload = [
        'WEBPAY_COMMERCE_CODE' => '111',
        'WEBPAY_API_KEY' => 'KEY',
        'WEBPAY_ENVIRONMENT' => 'integration',
        'WEBPAY_RETURN_URL' => 'https://abc.com',
    ];

    // Unauthenticated
    $this->putJson('/api/webpay/config', $payload)->assertUnauthorized();

    // Authenticated but without permission (editor doesn't have system config permission)
    $user = User::factory()->create()->assignRole('editor');
    $this->actingAs($user, 'sanctum');
    $this->putJson('/api/webpay/config', $payload)->assertForbidden();
});

test('a user with update-system-config permission can update webpay config', function () {
    $user = User::factory()->create()->assignRole('admin');

    $payload = [
        'WEBPAY_COMMERCE_CODE' => '7654321',
        'WEBPAY_API_KEY' => 'NEWKEY',
        'WEBPAY_ENVIRONMENT' => 'integration',
        'WEBPAY_RETURN_URL' => 'https://mysite.com/webpay/return',
    ];

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/webpay/config', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Configuración de Webpay actualizada exitosamente']);

    $this->assertDatabaseHas('siteinfo', [
        'key' => 'WEBPAY_INFO',
    ]);

    $record = Siteinfo::where('key', 'WEBPAY_INFO')->first();
    expect($record->value)->toMatchArray($payload);
});
