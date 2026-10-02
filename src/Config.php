<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Reads configuration from environment variables (12-factor style).
 */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get($key, '')))));
    }
}
