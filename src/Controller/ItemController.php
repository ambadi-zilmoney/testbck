<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Logger;
use App\Repository\ItemRepository;

final class ItemController
{
    private const MAX_LIMIT = 100;
    private const MAX_NAME_LENGTH = 255;

    public function __construct(private ItemRepository $items)
    {
    }

    public function index(Request $request): Response
    {
        $limit  = max(1, min(self::MAX_LIMIT, (int) ($request->query['limit'] ?? 50)));
        $offset = max(0, (int) ($request->query['offset'] ?? 0));

        return Response::json([
            'data' => $this->items->list($limit, $offset),
            'meta' => ['total' => $this->items->count(), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function show(Request $request): Response
    {
        $item = $this->items->find((int) $request->params['id'])
            ?? throw new HttpException(404, 'Item not found');

        return Response::json(['data' => $item]);
    }

    public function store(Request $request): Response
    {
        $name = $request->json()['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            throw new HttpException(422, 'Validation failed', ['name' => 'Name is required.']);
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new HttpException(422, 'Validation failed', [
                'name' => 'Name must be at most ' . self::MAX_NAME_LENGTH . ' characters.',
            ]);
        }

        $item = $this->items->create($name);
        Logger::info('Item created', ['item_id' => $item['id']]);

        return Response::json(['data' => $item], 201);
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->params['id'];

        if (!$this->items->delete($id)) {
            throw new HttpException(404, 'Item not found');
        }
        Logger::info('Item deleted', ['item_id' => $id]);

        return Response::noContent();
    }
}
