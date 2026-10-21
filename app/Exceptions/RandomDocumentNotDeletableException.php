<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Random ERP document was not deleted because its trace already lists another document.
 */
class RandomDocumentNotDeletableException extends RuntimeException
{
    /**
     * @param  list<array{idmaeedo: int|null, type: string, maeedo: array<string, mixed>}>  $associated
     */
    public function __construct(
        public readonly int $idmaeedo,
        public readonly array $associated,
    ) {
        $types = collect($associated)->pluck('type')->unique()->implode(', ');

        parent::__construct(
            "Random document {$idmaeedo} has associated documents ({$types}) and was not deleted."
        );
    }
}
