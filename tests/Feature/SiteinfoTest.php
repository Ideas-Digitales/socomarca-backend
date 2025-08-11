<?php

namespace Tests\Feature;

use App\Models\Siteinfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

describe('Siteinfo API', function () {
    describe('Show endpoint', function () {
        it('requires authentication', function () {
            $this->getJson(route('siteinfo.show'))->assertUnauthorized();
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create(); // User without permission
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.show'))
                ->assertForbidden();
        });

        it('returns default structure when database is empty', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-content-settings');
            
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.show'))
                ->assertOk()
                ->assertJsonStructure([
                    'header' => ['contact_phone', 'contact_email'],
                    'footer' => ['contact_phone', 'contact_email'],
                    'social_media' => [['label', 'link']]
                ]);
        });

        it('returns saved values from database', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-content-settings');
            Siteinfo::create(['key' => 'header', 'value' => ['contact_phone' => '123', 'contact_email' => 'a@b.com']]);
            Siteinfo::create(['key' => 'footer', 'value' => ['contact_phone' => '456', 'contact_email' => 'c@d.com']]);
            Siteinfo::create(['key' => 'social_media', 'value' => [['label' => 'fb', 'link' => 'fb.com']]]);

            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.show'))
                ->assertOk()
                ->assertJson([
                    'header' => ['contact_phone' => '123', 'contact_email' => 'a@b.com'],
                    'footer' => ['contact_phone' => '456', 'contact_email' => 'c@d.com'],
                    'social_media' => [['label' => 'fb', 'link' => 'fb.com']]
                ]);
        });
    });

    describe('Update endpoint', function () {
        it('requires authentication and correct permission', function () {
            $this->putJson(route('siteinfo.update'), [])->assertUnauthorized();

            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');
            $this->putJson(route('siteinfo.update'), [])->assertForbidden();
        });

        it('allows admin to update siteinfo', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('update-content-settings');

            $payload = [
                'header' => ['contact_phone' => '999', 'contact_email' => 'x@y.com'],
                'footer' => ['contact_phone' => '888', 'contact_email' => 'z@w.com'],
                'social_media' => [['label' => 'ig', 'link' => 'ig.com']]
            ];

            $this->actingAs($user, 'sanctum')
                ->putJson(route('siteinfo.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Siteinfo updated successfully']);

            $this->assertDatabaseHas('siteinfo', ['key' => 'header']);
            $this->assertDatabaseHas('siteinfo', ['key' => 'footer']);
            $this->assertDatabaseHas('siteinfo', ['key' => 'social_media']);
        });
    });
});

describe('Terms and Privacy Policy API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication for terms and privacy policy', function () {
            $this->getJson(route('siteinfo.terms'))->assertUnauthorized();
            $this->getJson(route('siteinfo.privacy-policy'))->assertUnauthorized();
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create(); // User without permission
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.terms'))
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.privacy-policy'))
                ->assertForbidden();
        });
    });

    describe('Content Display', function () {
        it('returns correct content for terms and privacy policy', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-content-settings');
            Siteinfo::create(['key' => 'terms', 'content' => '<h1>Terms</h1>']);
            Siteinfo::create(['key' => 'privacy_policy', 'content' => '<h1>Policy</h1>']);

            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.terms'))
                ->assertOk()
                ->assertJson(['content' => '<h1>Terms</h1>']);

            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.privacy-policy'))
                ->assertOk()
                ->assertJson(['content' => '<h1>Policy</h1>']);
        });
    });

    describe('Content Updates', function () {
        it('allows editor to update terms and privacy policy', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('update-content-settings');

            $this->actingAs($user, 'sanctum');

            $this->putJson(route('siteinfo.terms.update'), ['content' => '<h1>New Terms</h1>'])
                ->assertOk()
                ->assertJson(['message' => 'Terms upadated succesfully']);

            $this->putJson(route('siteinfo.privacy-policy.update'), ['content' => '<h1>New Policy</h1>'])
                ->assertOk()
                ->assertJson(['message' => 'Privacy Policy updated successfully']);

            $this->assertDatabaseHas('siteinfo', ['key' => 'terms', 'content' => '<h1>New Terms</h1>']);
            $this->assertDatabaseHas('siteinfo', ['key' => 'privacy_policy', 'content' => '<h1>New Policy</h1>']);
        });
    });
});

describe('Customer Message API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication', function () {
            $this->getJson(route('siteinfo.customer-message'))->assertUnauthorized();
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create(); // User without permission
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.customer-message'))
                ->assertForbidden();
        });
    });

    describe('Content Display', function () {
        it('returns default structure', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-content-settings');
            
            $this->actingAs($user, 'sanctum')
                ->getJson(route('siteinfo.customer-message'))
                ->assertOk()
                ->assertJsonStructure([
                    'header' => ['color', 'content'],
                    'banner' => ['desktop_image', 'mobile_image', 'enabled'],
                    'modal' => ['image', 'enabled']
                ]);
        });
    });

    describe('Content Updates', function () {
        it('allows superadmin to update customer message without header_content', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('update-content-settings');

            $payload = [
                'header_color' => '#fff',
                'banner_enabled' => true,
                'modal_enabled' => true,
                'message_enabled' => true,
            ];

            $this->actingAs($user, 'sanctum')
                ->putJson(route('siteinfo.customer-message.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

            $record = Siteinfo::where('key', 'customer_message')->first();
            expect($record)->not->toBeNull();
            expect($record->value['header']['content'])->toBe(''); // Debe ser string vacío
        });

        it('allows superadmin to update customer message with images', function () {
            Storage::fake('public');
            $user = User::factory()->create();
            $user->givePermissionTo('update-content-settings');
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
                ->putJson(route('siteinfo.customer-message.update'), $payload)
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

        it('allows superadmin to update customer message without images', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('update-content-settings');

            $payload = [
                'header_color' => '#fff',
                'header_content' => '<h1>Hola</h1>',
                'banner_enabled' => true,
                'modal_enabled' => true,
                'message_enabled' => true,
            ];

            $this->actingAs($user, 'sanctum')
                ->putJson(route('siteinfo.customer-message.update'), $payload)
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
    });
});

describe('Webpay Configuration API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication for webpay config', function () {
            $this->getJson(route('webpay.config'))->assertUnauthorized();
        });

        it('requires read-all-system-config permission', function () {
            $user = User::factory()->create(); // User without permission
            $this->actingAs($user, 'sanctum')
                ->getJson(route('webpay.config'))
                ->assertForbidden();
        });
    });

    describe('Configuration Display', function () {
        it('returns default structure when database is empty', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-all-system-config');
            
            $this->actingAs($user, 'sanctum')
                ->getJson(route('webpay.config'))
                ->assertStatus(404)
                ->assertJson([
                    'message' => 'No se encontró la configuración de Webpay',
                    'data' => [],
                ]);
        });

        it('returns stored values', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('read-all-system-config');
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
                ->getJson(route('webpay.config'))
                ->assertOk()
                ->assertJson($stored);
        });
    });

    describe('Configuration Updates', function () {
        it('requires authentication and update-system-config permission', function () {
            $payload = [
                'WEBPAY_COMMERCE_CODE' => '111',
                'WEBPAY_API_KEY' => 'KEY',
                'WEBPAY_ENVIRONMENT' => 'integration',
                'WEBPAY_RETURN_URL' => 'https://abc.com',
            ];

            // Unauthenticated
            $this->putJson(route('webpay.config.update'), $payload)->assertUnauthorized();

            // Authenticated but without permission (user doesn't have system config permission)
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');
            $this->putJson(route('webpay.config.update'), $payload)->assertForbidden();
        });

        it('allows user with update-system-config permission to update webpay config', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('update-system-config');

            $payload = [
                'WEBPAY_COMMERCE_CODE' => '7654321',
                'WEBPAY_API_KEY' => 'NEWKEY',
                'WEBPAY_ENVIRONMENT' => 'integration',
                'WEBPAY_RETURN_URL' => 'https://mysite.com/webpay/return',
            ];

            $this->actingAs($user, 'sanctum')
                ->putJson(route('webpay.config.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Configuración de Webpay actualizada exitosamente']);

            $this->assertDatabaseHas('siteinfo', [
                'key' => 'WEBPAY_INFO',
            ]);

            $record = Siteinfo::where('key', 'WEBPAY_INFO')->first();
            expect($record->value)->toMatchArray($payload);
        });
    });
});