<?php

declare(strict_types=1);

namespace Loongs\Render;

/**
 * Input of Page::build(): which page, which client, route/query parameters and whatever the host
 * wants to pass (current user, tenant…). Immutable.
 */
final readonly class PageContext
{
    /**
     * @param array<string, scalar|null> $params    route / query parameters (e.g. ['id' => '7'])
     * @param array<string, mixed>       $attributes host data (user, tenant, request…)
     */
    public function __construct(
        public ClientInfo $client = new ClientInfo(),
        public array $params = [],
        public mixed $user = null,
        public array $attributes = [],
        public string $page = '',
    ) {
    }

    public function withPage(string $page): self
    {
        return new self($this->client, $this->params, $this->user, $this->attributes, PageCode::assert($page));
    }

    public function param(string $name, int|float|string|bool|null $default = null): int|float|string|bool|null
    {
        return $this->params[$name] ?? $default;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function isMobile(): bool
    {
        return $this->client->isMobile;
    }
}
