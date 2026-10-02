<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\ItemController;
use App\Database;
use App\Http\Router;
use App\Repository\ItemRepository;

return static function (Router $router): void {
    $health = new HealthController();
    $router->get('/api/health', [$health, 'live']);
    $router->get('/api/ready', [$health, 'ready']);

    // Built lazily so health checks never open a DB connection they don't need.
    $items = static fn (): ItemController => new ItemController(new ItemRepository(Database::connection()));

    $router->get('/api/items', static fn ($r) => $items()->index($r));
    $router->post('/api/items', static fn ($r) => $items()->store($r));
    $router->get('/api/items/{id:\d+}', static fn ($r) => $items()->show($r));
    $router->delete('/api/items/{id:\d+}', static fn ($r) => $items()->destroy($r));
};
