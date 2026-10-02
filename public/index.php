<?php

declare(strict_types=1);

use App\Config;
use App\Http\HttpException;
use App\Http\Idempotency;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Logger;

require __DIR__ . '/../vendor/autoload.php';

// Turn PHP warnings/notices into exceptions so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
Logger::setRequestId($requestId);

$request = Request::fromGlobals();

try {
    if ($request->method === 'OPTIONS') {
        $response = Response::noContent();
    } else {
        $router = new Router();
        (require __DIR__ . '/../src/routes.php')($router);
        // Retried POSTs carrying the same Idempotency-Key replay the first result instead of running twice.
        $response = Idempotency::handle($request, static fn (): Response => $router->dispatch($request));
    }
} catch (HttpException $e) {
    $payload = ['error' => $e->getMessage()];
    if ($e->errors()) {
        $payload['errors'] = $e->errors();
    }
    $response = Response::json($payload, $e->status());
} catch (Throwable $e) {
    Logger::error('Unhandled exception', [
        'exception' => $e::class,
        'error'     => $e->getMessage(),
        'file'      => $e->getFile() . ':' . $e->getLine(),
    ]);

    $payload = ['error' => 'Internal Server Error', 'request_id' => $requestId];
    if (Config::bool('APP_DEBUG')) {
        $payload['debug'] = ['message' => $e->getMessage(), 'trace' => explode("\n", $e->getTraceAsString())];
    }
    $response = Response::json($payload, 500);
}

// CORS: the frontend is hosted on S3 (a different origin), so only the
// origins listed in CORS_ALLOWED_ORIGINS may call the API from a browser.
$origin = $request->header('Origin');
if ($origin !== null && in_array($origin, Config::list('CORS_ALLOWED_ORIGINS'), true)) {
    $response = $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Request-Id, Idempotency-Key')
        ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, Idempotent-Replayed, Retry-After')
        ->withHeader('Access-Control-Max-Age', '600');
}
$response = $response->withHeader('Vary', 'Origin');

$response
    ->withHeader('X-Request-Id', $requestId)
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('Cache-Control', 'no-store')
    ->send();
