<?php

declare(strict_types=1);

namespace App\Http;

use App\Database;
use App\Logger;
use Throwable;

/**
 * Makes POST requests safe to retry.
 *
 * The client sends a unique `Idempotency-Key` header per logical action and reuses it
 * on every retry. The first request claims the key and its response is stored; any
 * repeat gets the stored response back (with `Idempotent-Replayed: true`) instead of
 * creating a duplicate. A repeat that arrives while the first is still running gets 409.
 */
final class Idempotency
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';
    private const TTL_HOURS = 24;
    private const IN_PROGRESS = 0;

    /** @param callable(): Response $next */
    public static function handle(Request $request, callable $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($request->method !== 'POST' || $key === null) {
            return $next();
        }
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new HttpException(400, 'Invalid Idempotency-Key header');
        }

        $db = Database::connection();
        $hash = hash('sha256', $request->method . ' ' . $request->path . "\n" . json_encode($request->json()));

        // Claim the key. INSERT IGNORE is atomic, so only one request can win.
        $claim = $db->prepare(
            'INSERT IGNORE INTO idempotency_keys (idem_key, request_hash, status_code, response_body)
             VALUES (:k, :h, :s, \'\')'
        );
        $claim->execute(['k' => $key, 'h' => $hash, 's' => self::IN_PROGRESS]);

        if ($claim->rowCount() === 0) {
            return self::replay($key, $hash);
        }

        try {
            $response = $next();
        } catch (Throwable $e) {
            // Release the key so the client can retry the failed request.
            $db->prepare('DELETE FROM idempotency_keys WHERE idem_key = :k')->execute(['k' => $key]);
            throw $e;
        }

        if ($response->status() >= 500) {
            $db->prepare('DELETE FROM idempotency_keys WHERE idem_key = :k')->execute(['k' => $key]);
        } else {
            $db->prepare('UPDATE idempotency_keys SET status_code = :s, response_body = :b WHERE idem_key = :k')
                ->execute(['s' => $response->status(), 'b' => $response->body(), 'k' => $key]);
        }

        self::maybePurgeExpired();

        return $response;
    }

    private static function replay(string $key, string $hash): Response
    {
        $stmt = Database::connection()->prepare(
            'SELECT request_hash, status_code, response_body FROM idempotency_keys WHERE idem_key = :k'
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            // The original failed and released the key between our INSERT and SELECT.
            throw new HttpException(409, 'Request is being retried, please try again');
        }
        if (!hash_equals($row['request_hash'], $hash)) {
            throw new HttpException(422, 'Idempotency-Key was already used with a different request');
        }
        if ((int) $row['status_code'] === self::IN_PROGRESS) {
            return Response::json(['error' => 'Original request is still in progress'], 409)
                ->withHeader('Retry-After', '1');
        }

        Logger::info('Idempotent replay', ['idempotency_key' => $key]);

        return (new Response((int) $row['status_code'], $row['response_body'], [
            'Content-Type' => 'application/json; charset=utf-8',
        ]))->withHeader('Idempotent-Replayed', 'true');
    }

    /** Cheap housekeeping: roughly 1 in 100 requests deletes expired keys. */
    private static function maybePurgeExpired(): void
    {
        if (random_int(1, 100) === 1) {
            Database::connection()->exec(
                'DELETE FROM idempotency_keys WHERE created_at < NOW() - INTERVAL ' . self::TTL_HOURS . ' HOUR'
            );
        }
    }
}
