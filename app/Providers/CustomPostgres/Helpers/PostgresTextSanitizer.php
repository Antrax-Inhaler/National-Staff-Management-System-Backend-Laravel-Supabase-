<?php

declare(strict_types=1);

namespace App\Providers\CustomPostgres\Helpers;

class PostgresTextSanitizer
{
    public static function sanitize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $input);

        return $input;
    }
}