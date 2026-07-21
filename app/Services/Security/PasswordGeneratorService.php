<?php

namespace App\Services\Security;

use App\DTOs\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordGeneratorService
{
    const LENGTH = 12;
    const LETTERS = true;
    const NUMBERS = true;
    const SYMBOLS = false;
    const SPACES = false;

    /**
     * Generate a secure password
     *
     * @return Password
     */
    public function generate(): Password
    {

        $password = Str::password(
            length: self::LENGTH,
            letters: self::LETTERS,
            numbers: self::NUMBERS,
            symbols: self::SYMBOLS,
            spaces: self::SPACES,
        );
        $passwordHash =  Hash::make($password);
        $passwordDto = new Password($password, $passwordHash);
        return $passwordDto;
    }
}
