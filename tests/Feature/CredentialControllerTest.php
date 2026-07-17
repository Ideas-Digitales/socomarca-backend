<?php

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Scenarios\CredentialScenario;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\withHeaders;

describe('successful updates', function () {
    it('updates the user email and returns a success message', function () {
        Mail::fake();

        $scenario = CredentialScenario::make();

        $response = withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'A new provisional password has been sent.',
            ]);

        $scenario->user->refresh();
        expect($scenario->user->email)->toBe('new@example.com');
    });

    it('generates a new temporary password and sends it by mail', function () {
        Mail::fake();

        $scenario = CredentialScenario::make();
        $previousPasswordHash = $scenario->user->password;

        withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'new@example.com',
            ])
            ->assertStatus(200);

        $scenario->user->refresh();
        expect($scenario->user->password)->not->toBe($previousPasswordHash);
        expect($scenario->user->password_changed_at)->toBeNull();

        Mail::assertQueued(TemporaryPasswordMail::class, function ($mail) use ($scenario) {
            return $mail->hasTo('new@example.com')
                && Hash::check($mail->temporaryPassword, $scenario->user->password);
        });
    });

    it('revokes all other tokens except the current one', function () {
        Mail::fake();

        $scenario = CredentialScenario::make();
        $scenario->user->createToken('other-device');

        withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'new@example.com',
            ])
            ->assertStatus(200);

        expect($scenario->user->tokens()->count())->toBe(1);
        expect($scenario->user->tokens()->first()->id)->toBe($scenario->token->accessToken->id);
    });
});

describe('authorization', function () {
    it('rejects unauthenticated requests', function () {
        $response = patchJson(route('credentials.update'), [
            'email' => 'new@example.com',
        ]);

        $response->assertStatus(401);
    });

    it('rejects requests from tokens without the credentials-restore ability', function () {
        $scenario = CredentialScenario::make(['api-access']);

        $response = withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(403);
    });
});

describe('validation', function () {
    it('rejects an invalid email format', function () {
        $scenario = CredentialScenario::make();

        $response = withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects an email already used by another user', function () {
        User::factory()->create(['email' => 'taken@example.com']);
        $scenario = CredentialScenario::make();

        $response = withHeaders($scenario->authHeaders())
            ->patchJson(route('credentials.update'), [
                'email' => 'taken@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });
});
