<?php

use App\Services\Security\PasswordGeneratorService;
use Illuminate\Support\Facades\Hash;

describe('Password generator service tests', function () {
    it('should generate a valid password and hash pair', function () {
        $service = new PasswordGeneratorService();
        $passwordDto = $service->generate();
        $isValidPassword = Hash::check($passwordDto->password, $passwordDto->passwordHash);
        expect($isValidPassword)->toBeTrue();
    });
});
