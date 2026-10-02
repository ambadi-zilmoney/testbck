<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(int $status, string $message, private array $errors = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
