<?php

namespace App\Enums;

class BranchType
{
    const PRIMARY = 'P';
    const SECONDARY = 'S';

    public static function values(): array
    {
        return [
            self::PRIMARY,
            self::SECONDARY,
        ];
    }

    public static function labels(): array
    {
        return [
            self::PRIMARY => 'Principal',
            self::SECONDARY => 'Secundaria',
        ];
    }
}
