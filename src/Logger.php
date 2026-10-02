<?php

declare(strict_types=1);

namespace App;

/**
 * Writes one JSON object per line to stderr so container log collectors can parse it.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $requestId = null;

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $min = self::LEVELS[Config::get('LOG_LEVEL', 'info')] ?? 1;
        if ((self::LEVELS[$level] ?? 0) < $min) {
            return;
        }

        $entry = [
            'time'       => date(DATE_RFC3339_EXTENDED),
            'level'      => $level,
            'message'    => $message,
            'request_id' => self::$requestId,
        ] + $context;

        file_put_contents('php://stderr', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
