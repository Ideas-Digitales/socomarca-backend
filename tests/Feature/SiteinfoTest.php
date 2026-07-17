<?php

use App\Models\Siteinfo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

describe('Siteinfo API', function () {
    describe('Show endpoint', function () {
        it('requires authentication', function () {
            getJson(route('siteinfo.show'))->assertUnauthorized();
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('siteinfo.show'))->assertForbidden();
        });

        it('returns default structure when database is empty', function () {
            $user = createUserWithPermissions(['read-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('siteinfo.show'))
                ->assertOk()
                ->assertJsonStructure([
                    'header' => ['contact_phone', 'contact_email'],
                    'footer' => ['contact_phone', 'contact_email'],
                    'social_media' => [['label', 'link']]
                ]);
        });

        it('returns saved values from database', function () {
            $user = createUserWithPermissions(['read-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            Siteinfo::create(['key' => 'header', 'value' => ['contact_phone' => '123', 'contact_email' => 'a@b.com']]);
            Siteinfo::create(['key' => 'footer', 'value' => ['contact_phone' => '456', 'contact_email' => 'c@d.com']]);
            Siteinfo::create(['key' => 'social_media', 'value' => [['label' => 'fb', 'link' => 'fb.com']]]);

            getJson(route('siteinfo.show'))
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
            putJson(route('siteinfo.update'), [])->assertUnauthorized();

            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            putJson(route('siteinfo.update'), [])->assertForbidden();
        });

        it('allows admin to update siteinfo', function () {
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            $payload = [
                'header' => ['contact_phone' => '999', 'contact_email' => 'x@y.com'],
                'footer' => ['contact_phone' => '888', 'contact_email' => 'z@w.com'],
                'social_media' => [['label' => 'ig', 'link' => 'ig.com']]
            ];

            putJson(route('siteinfo.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Siteinfo updated successfully']);

            assertDatabaseHas('siteinfo', ['key' => 'header']);
            assertDatabaseHas('siteinfo', ['key' => 'footer']);
            assertDatabaseHas('siteinfo', ['key' => 'social_media']);
        });
    });
});

describe('Terms and Privacy Policy API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication for terms and privacy policy', function () {
            getJson(route('siteinfo.terms'))->assertUnauthorized();
            getJson(route('siteinfo.privacy-policy'))
                ->assertJsonStructure(['content']);
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('siteinfo.terms'))->assertForbidden();
        });
    });

    describe('Content Display', function () {
        it('returns correct content for terms and privacy policy', function () {
            $user = createUserWithPermissions(['read-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            Siteinfo::create(['key' => 'terms', 'content' => '<h1>Terms</h1>']);
            Siteinfo::create(['key' => 'privacy_policy', 'content' => '<h1>Policy</h1>']);

            getJson(route('siteinfo.terms'))
                ->assertOk()
                ->assertJson(['content' => '<h1>Terms</h1>']);

            getJson(route('siteinfo.privacy-policy'))
                ->assertOk()
                ->assertJson(['content' => '<h1>Policy</h1>']);
        });
    });

    describe('Content Updates', function () {
        it('allows editor to update terms and privacy policy', function () {
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            putJson(route('siteinfo.terms.update'), ['content' => '<h1>New Terms</h1>'])
                ->assertOk()
                ->assertJson(['message' => 'Terms upadated succesfully']);

            putJson(route('siteinfo.privacy-policy.update'), ['content' => '<h1>New Policy</h1>'])
                ->assertOk()
                ->assertJson(['message' => 'Privacy Policy updated successfully']);

            assertDatabaseHas('siteinfo', ['key' => 'terms', 'content' => '<h1>New Terms</h1>']);
            assertDatabaseHas('siteinfo', ['key' => 'privacy_policy', 'content' => '<h1>New Policy</h1>']);
        });
    });
});

describe('Customer Message API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication', function () {
            getJson(route('siteinfo.customer-message'))->assertUnauthorized();
        });

        it('requires read-content-settings permission', function () {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('siteinfo.customer-message'))->assertForbidden();
        });
    });

    describe('Content Display', function () {
        it('returns default structure', function () {
            $user = createUserWithPermissions(['read-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('siteinfo.customer-message'))
                ->assertOk()
                ->assertJsonStructure([
                    'header' => ['color', 'content'],
                    'banner' => ['enabled', 'slides'],
                    'modal' => ['image', 'enabled']
                ]);
        });

        it('migrates legacy banner structure to slides', function () {
            Siteinfo::create([
                'key' => 'customer_message',
                'value' => [
                    'header' => ['color' => '#fff', 'content' => 'Hola'],
                    'banner' => [
                        'desktop_image' => 'https://example.test/desktop.jpg',
                        'mobile_image' => 'https://example.test/mobile.jpg',
                        'enabled' => true,
                    ],
                    'modal' => ['image' => '', 'enabled' => false],
                ],
            ]);

            // RefreshDatabase already ran every migration (including this one) against
            // an empty table, so re-running it through the tracked migrator is a no-op.
            // Invoke the migration class directly to exercise its transformation logic.
            $migration = require database_path(
                'migrations/2026_06_16_210000_migrate_customer_message_banner_to_slides.php'
            );
            $migration->up();

            $record = Siteinfo::where('key', 'customer_message')->first();

            expect($record->value['banner']['enabled'])->toBeTrue();
            expect($record->value['banner']['slides'][0]['desktop_image'])->toBe('https://example.test/desktop.jpg');
            expect($record->value['banner']['slides'][0]['mobile_image'])->toBe('https://example.test/mobile.jpg');
            expect($record->value['banner']['slides'][0]['enabled'])->toBeTrue();
        });
    });

    describe('Content Updates', function () {
        it('allows superadmin to update customer message without header_content', function () {
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            $payload = [
                'header_color' => '#fff',
                'banner_enabled' => true,
                'modal_enabled' => true,
                'message_enabled' => true,
            ];

            putJson(route('siteinfo.customer-message.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

            $record = Siteinfo::where('key', 'customer_message')->first();
            expect($record)->not->toBeNull();
            expect($record->value['header']['content'])->toBe('');
        });

        it('allows superadmin to update customer message with images', function () {
            // AWS_URL is unset in testing, so the fake disk falls back to a relative
            // "/storage/..." URL; force an absolute one to match what S3 returns in production.
            Storage::fake('s3', ['url' => 'https://s3-fake-url.amazonaws.com']);
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

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

            putJson(route('siteinfo.customer-message.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

            $record = Siteinfo::where('key', 'customer_message')->first();
            expect($record)->not->toBeNull();

            expect($record->value['banner']['enabled'])->toBeBool();
            expect($record->value['modal']['enabled'])->toBeBool();
            expect($record->value['message']['enabled'])->toBeBool();

            expect($record->value['banner']['slides'][0]['desktop_image'])->toStartWith('http');
            expect($record->value['banner']['slides'][0]['mobile_image'])->toStartWith('http');
            expect($record->value['modal']['image'])->toStartWith('http');
        });

        it('stores multiple banner slides and preserves existing images', function () {
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            $payload = [
                'header_color' => '#fff',
                'header_content' => '<h1>Hola</h1>',
                'banner_enabled' => true,
                'modal_enabled' => false,
                'message_enabled' => true,
                'banner_slides' => [
                    [
                        'id' => 'slide-a',
                        'existing_desktop_image' => 'https://example.test/a-desktop.jpg',
                        'existing_mobile_image' => 'https://example.test/a-mobile.jpg',
                        'alt' => 'Slide A',
                        'order' => 2,
                        'enabled' => true,
                    ],
                    [
                        'id' => 'slide-b',
                        'existing_desktop_image' => 'https://example.test/b-desktop.jpg',
                        'existing_mobile_image' => 'https://example.test/b-mobile.jpg',
                        'alt' => 'Slide B',
                        'order' => 1,
                        'enabled' => false,
                    ],
                ],
            ];

            putJson(route('siteinfo.customer-message.update'), $payload)
                ->assertOk();

            $record = Siteinfo::where('key', 'customer_message')->first();

            expect($record->value['banner']['slides'])->toHaveCount(2);
            expect($record->value['banner']['slides'][0]['id'])->toBe('slide-b');
            expect($record->value['banner']['slides'][0]['desktop_image'])->toBe('https://example.test/b-desktop.jpg');
            expect($record->value['banner']['slides'][1]['id'])->toBe('slide-a');
            expect($record->value['banner']['slides'][1]['enabled'])->toBeTrue();
        });

        it('allows superadmin to update customer message without images', function () {
            $user = createUserWithPermissions(['update-content-settings']);
            Sanctum::actingAs($user, ['api-access']);

            $payload = [
                'header_color' => '#fff',
                'header_content' => '<h1>Hola</h1>',
                'banner_enabled' => true,
                'modal_enabled' => true,
                'message_enabled' => true,
            ];

            putJson(route('siteinfo.customer-message.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Mensaje de bienvenida actualizado correctamente.']);

            $record = Siteinfo::where('key', 'customer_message')->first();
            expect($record)->not->toBeNull();

            expect($record->value['banner']['enabled'])->toBeBool();
            expect($record->value['modal']['enabled'])->toBeBool();
            expect($record->value['message']['enabled'])->toBeBool();

            expect($record->value['banner']['slides'])->toBe([]);
            expect($record->value['modal']['image'])->toBe('');
        });
    });
});

describe('Webpay Configuration API', function () {
    describe('Authentication and Authorization', function () {
        it('requires authentication for webpay config', function () {
            getJson(route('webpay.config'))->assertUnauthorized();
        });

        it('requires read-all-system-config permission', function () {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('webpay.config'))->assertForbidden();
        });
    });

    describe('Configuration Display', function () {
        it('returns default structure when database is empty', function () {
            $user = createUserWithPermissions(['read-all-system-config']);
            Sanctum::actingAs($user, ['api-access']);

            getJson(route('webpay.config'))
                ->assertStatus(404)
                ->assertJson([
                    'message' => 'No se encontró la configuración de Webpay',
                    'data' => [],
                ]);
        });

        it('returns stored values', function () {
            $user = createUserWithPermissions(['read-all-system-config']);
            Sanctum::actingAs($user, ['api-access']);

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

            getJson(route('webpay.config'))
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

            putJson(route('webpay.config.update'), $payload)->assertUnauthorized();

            $user = User::factory()->create();
            Sanctum::actingAs($user, ['api-access']);

            putJson(route('webpay.config.update'), $payload)->assertForbidden();
        });

        it('allows user with update-system-config permission to update webpay config', function () {
            $user = createUserWithPermissions(['update-system-config']);
            Sanctum::actingAs($user, ['api-access']);

            $payload = [
                'WEBPAY_COMMERCE_CODE' => '7654321',
                'WEBPAY_API_KEY' => 'NEWKEY',
                'WEBPAY_ENVIRONMENT' => 'integration',
                'WEBPAY_RETURN_URL' => 'https://mysite.com/webpay/return',
            ];

            putJson(route('webpay.config.update'), $payload)
                ->assertOk()
                ->assertJson(['message' => 'Configuración de Webpay actualizada exitosamente']);

            assertDatabaseHas('siteinfo', [
                'key' => 'WEBPAY_INFO',
            ]);

            $record = Siteinfo::where('key', 'WEBPAY_INFO')->first();
            expect($record->value)->toMatchArray($payload);
        });
    });
});
