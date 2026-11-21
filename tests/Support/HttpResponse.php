<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

final class HttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function contains(string $needle): bool
    {
        return str_contains($this->body, $needle);
    }
}
