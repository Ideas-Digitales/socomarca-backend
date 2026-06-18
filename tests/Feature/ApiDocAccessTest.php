<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'api-doc.read.all']);
});

describe('API Documentation Access', function () {
    it('redirects to login when not authenticated', function () {
        $response = $this->get('/docs/api');

        $response->assertRedirect(route('api-doc.login'));
    });

    it('forbids access when user lacks api-doc.read.all permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/docs/api');

        $response->assertForbidden();
    });

    it('allows access when user has api-doc.read.all permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('api-doc.read.all');
        $this->actingAs($user);

        $response = $this->get('/docs/api');

        $response->assertStatus(200);
    });

    it('shows login form', function () {
        $response = $this->get(route('api-doc.login'));

        $response->assertStatus(200);
        $response->assertSee('Socomarca API');
        $response->assertSee('Documentación de la API');
    });

    it('logs in user with valid credentials and permission', function () {
        $user = User::factory()->create([
            'email' => 'dev@example.com',
            'password' => bcrypt('password123'),
        ]);
        $user->givePermissionTo('api-doc.read.all');

        $response = $this->post(route('api-doc.login.submit'), [
            'email' => 'dev@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('scramble.docs.ui'));
        $this->assertAuthenticatedAs($user);
    });

    it('rejects login with invalid credentials', function () {
        User::factory()->create([
            'email' => 'dev@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post(route('api-doc.login.submit'), [
            'email' => 'dev@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    it('rejects login when user lacks api-doc.read.all permission', function () {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post(route('api-doc.login.submit'), [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    it('logs out user successfully', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('api-doc.read.all');
        $this->actingAs($user);

        $response = $this->post(route('api-doc.logout'));

        $response->assertRedirect(route('api-doc.login'));
        $this->assertGuest();
    });

    it('redirects authenticated user away from login form', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('api-doc.read.all');
        $this->actingAs($user);

        $response = $this->get(route('api-doc.login'));

        $response->assertRedirect('/');
    });
});
