<?php

use App\Models\User;

describe('user:create command', function () {
    it('creates a user with the selected role', function () {
        $this->artisan('user:create')
            ->expectsQuestion('Name', 'John Doe')
            ->expectsQuestion('Email', 'john@example.com')
            ->expectsQuestion('Password', 'secret123')
            ->expectsQuestion('Phone', '+56912345678')
            ->expectsQuestion('RUT', '12345678-9')
            ->expectsQuestion('Business name', 'Acme SpA')
            ->expectsQuestion('Role', 'admin')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+56912345678',
            'rut' => '12345678-9',
            'business_name' => 'Acme SpA',
            'is_active' => true,
        ]);

        $user = User::where('email', 'john@example.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->hasRole('admin'))->toBeTrue()
            ->and(\Illuminate\Support\Facades\Hash::check('secret123', $user->password))->toBeTrue();
    });

    it('uses name as default business name', function () {
        $this->artisan('user:create')
            ->expectsQuestion('Name', 'Jane Doe')
            ->expectsQuestion('Email', 'jane@example.com')
            ->expectsQuestion('Password', 'secret123')
            ->expectsQuestion('Phone', '')
            ->expectsQuestion('RUT', '98765432-1')
            ->expectsQuestion('Business name', 'Jane Doe')
            ->expectsQuestion('Role', 'customer')
            ->assertExitCode(0);

        $user = User::where('email', 'jane@example.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->business_name)->toBe('Jane Doe')
            ->and($user->phone)->toBe('')
            ->and($user->hasRole('customer'))->toBeTrue();
    });
});
