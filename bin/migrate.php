<?php

declare(strict_types=1);

/**
 * Applies migrations/*.sql in filename order, once each.
 * Safe to run from several replicas at once: a MySQL named lock serialises runs.
 *
 * Usage: php bin/migrate.php
 */

use App\Database;
use App\Logger;

require __DIR__ . '/../vendor/autoload.php';

const MAX_ATTEMPTS = 30;

// The database may still be starting, so retry the connection for ~60s.
for ($attempt = 1; ; $attempt++) {
    try {
        $db = Database::connection();
        break;
    } catch (PDOException $e) {
        if ($attempt >= MAX_ATTEMPTS) {
            Logger::error('Database unreachable, giving up', ['error' => $e->getMessage()]);
            exit(1);
        }
        Logger::warning('Waiting for database', ['attempt' => $attempt]);
        sleep(2);
    }
}

if ((int) $db->query("SELECT GET_LOCK('app_migrations', 60)")->fetchColumn() !== 1) {
    Logger::error('Could not acquire migration lock');
    exit(1);
}

try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $applied = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files);

    $count = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $applied, true)) {
            continue;
        }

        // MySQL DDL auto-commits, so keep each migration small and idempotent.
        $db->exec((string) file_get_contents($file));
        $db->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => $version]);
        Logger::info('Migration applied', ['version' => $version]);
        $count++;
    }

    Logger::info('Migrations complete', ['applied' => $count]);
} catch (Throwable $e) {
    Logger::error('Migration failed', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    $db->query("SELECT RELEASE_LOCK('app_migrations')");
}
