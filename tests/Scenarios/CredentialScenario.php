<?php

namespace Tests\Scenarios;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

class CredentialScenario
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
    ) {}

    public static function make(array $abilities = ['credentials-restore']): CredentialScenario
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device', $abilities);

        return new CredentialScenario($user, $token);
    }

    public function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token->plainTextToken];
    }
}
