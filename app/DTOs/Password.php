<?php

namespace App\DTOs;

class Password
{
    public function __construct(
        public string $password,
        public string $passwordHash,
    ) {}
}
