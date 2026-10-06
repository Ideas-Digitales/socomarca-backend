<?php

use App\Enums\BranchType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('email normalization', function () {
    it('stores the email trimmed and in lowercase', function () {
        $user = User::factory()->create(['email' => '  Juan.Perez@Example.CL ']);

        expect($user->fresh()->email)->toBe('juan.perez@example.cl');

        $user->update(['email' => 'OTRO@Example.cl']);

        expect($user->fresh()->email)->toBe('otro@example.cl');
    });

    it('stores blank emails as null', function (?string $email) {
        $user = User::factory()->create(['email' => $email]);

        expect($user->fresh()->email)->toBeNull();
    })->with([
        'null' => [null],
        'empty' => [''],
        'whitespace' => ['   '],
    ]);
});

describe('random entity fields', function () {
    it('allows several users with the same rut', function () {
        User::factory()->create(['rut' => '77528378-K', 'user_code' => '77528378', 'branch_code' => 'CM']);
        User::factory()->create(['rut' => '77528378-K', 'user_code' => '77528378', 'branch_code' => 'LO']);

        expect(User::where('rut', '77528378-K')->count())->toBe(2);
    });

    it('stores the random entity attributes', function () {
        $user = User::factory()->create([
            'user_code' => '77528378',
            'random_entity_id' => 2475,
            'branch_type' => BranchType::SECONDARY,
            'billing_email' => 'dte_500sabores@idte.cl',
            'random_synced_at' => now(),
        ])->fresh();

        expect($user->random_entity_id)->toBe(2475)
            ->and($user->branch_type)->toBe(BranchType::SECONDARY)
            ->and($user->billing_email)->toBe('dte_500sabores@idte.cl')
            ->and($user->random_synced_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
    });

    it('rejects a duplicated random_entity_id', function () {
        User::factory()->create(['user_code' => '77528378', 'random_entity_id' => 2475]);

        expect(fn () => User::factory()->create(['user_code' => '77528378', 'random_entity_id' => 2475]))
            ->toThrow(QueryException::class);
    });

    it('requires a user_code for users synced from Random', function (?string $userCode) {
        expect(fn () => User::factory()->create(['user_code' => $userCode, 'random_entity_id' => 2475]))
            ->toThrow(QueryException::class, 'users_random_entity_user_code_check');
    })->with([
        'null' => [null],
        'empty' => [''],
        'whitespace' => ['   '],
    ]);

    it('allows internal users without user_code', function () {
        $user = User::factory()->create(['user_code' => null, 'random_entity_id' => null]);

        expect($user->exists)->toBeTrue();
    });
});

describe('normalize users emails migration', function () {
    it('normalizes existing emails and removes temporary emails', function () {
        $insert = fn (string $rut, ?string $email) => DB::table('users')->insertGetId([
            'name' => 'Test',
            'rut' => $rut,
            'user_code' => $rut,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mixedCase = $insert('1', '  Juan@Example.CL ');
        $blank = $insert('2', '   ');
        $temporary = $insert('3', 'temp_77528378@socomarca.temp');
        $temporaryUppercase = $insert('4', 'TEMP_12345678@Socomarca.Temp');
        $similar = $insert('5', 'temp1@socomarca.temp.cl');
        $null = $insert('6', null);

        (require database_path('migrations/2026_10_05_120100_normalize_users_emails.php'))->up();

        $emails = DB::table('users')->pluck('email', 'id');

        expect($emails[$mixedCase])->toBe('juan@example.cl')
            ->and($emails[$blank])->toBeNull()
            ->and($emails[$temporary])->toBeNull()
            ->and($emails[$temporaryUppercase])->toBeNull()
            ->and($emails[$similar])->toBe('temp1@socomarca.temp.cl')
            ->and($emails[$null])->toBeNull();
    });
});
