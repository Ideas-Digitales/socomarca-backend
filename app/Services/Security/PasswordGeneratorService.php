<?php

namespace App\Services\Security;

use Illuminate\Support\Str;

class PasswordGeneratorService
{

    /**
     * Generate a secure password
     *
     * @return string
     */
    public function generate(): string
    {
        return Str::random(8);
    }
}
