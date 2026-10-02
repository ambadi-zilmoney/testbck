<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Logger;
use Throwable;

final class HealthController
{
    /** Liveness: the PHP process is serving requests. */
    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }

    /** Readiness: dependencies (database) are reachable. */
    public function ready(Request $request): Response
    {
        try {
            Database::connection()->query('SELECT 1');
            return Response::json(['status' => 'ok', 'database' => 'up']);
        } catch (Throwable $e) {
            Logger::warning('Readiness check failed', ['error' => $e->getMessage()]);
            return Response::json(['status' => 'unavailable', 'database' => 'down'], 503);
        }
    }
}
