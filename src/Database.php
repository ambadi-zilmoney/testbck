<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private const CONNECT_ATTEMPTS = 3;

    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connectWithRetry();
        }

        return self::$pdo;
    }

    /**
     * Retries short-lived connection failures (DB restart, failover, brief network blip)
     * with a small backoff instead of failing the request on the first error.
     */
    private static function connectWithRetry(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::required('DB_HOST'),
            Config::get('DB_PORT', '3306'),
            Config::required('DB_NAME'),
        );

        for ($attempt = 1; ; $attempt++) {
            try {
                return new PDO($dsn, Config::required('DB_USER'), Config::required('DB_PASSWORD'), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 3,
                ]);
            } catch (PDOException $e) {
                if ($attempt >= self::CONNECT_ATTEMPTS) {
                    throw $e;
                }
                Logger::warning('DB connection failed, retrying', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                usleep(150_000 * $attempt);
            }
        }
    }
}
