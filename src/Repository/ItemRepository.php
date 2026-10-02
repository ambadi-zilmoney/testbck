<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ItemRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{id: int, name: string, created_at: string}> */
    public function list(int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, created_at FROM items ORDER BY id ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM items')->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, created_at FROM items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $name): array
    {
        $stmt = $this->db->prepare('INSERT INTO items (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);

        return $this->find((int) $this->db->lastInsertId());
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
