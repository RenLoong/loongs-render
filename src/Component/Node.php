<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\Platform;
use Loongs\Render\RenderContext;

/**
 * Base of every description node: permission (->can(), any-of) and platform restriction. A node that
 * is not allowed / not on the client's platform renders to null and disappears completely.
 */
abstract class Node
{
    /** @var list<string> */
    public private(set) array $permissions = [];
    /** @var list<Platform>|null null = every platform */
    public private(set) ?array $platforms = null;

    public function can(string ...$codes): static
    {
        foreach ($codes as $c) {
            if (preg_match('/^[A-Za-z0-9_.:*-]{1,128}$/', $c) !== 1) {
                throw new InvalidDefinition(static::class . ": invalid permission code \"{$c}\"");
            }
        }
        $this->permissions = array_values(array_unique([...$this->permissions, ...$codes]));

        return $this;
    }

    public function platforms(Platform|string ...$platforms): static
    {
        $this->platforms = array_map(static fn (Platform|string $p): Platform => $p instanceof Platform ? $p
            : (Platform::tryFrom($p) ?? throw new InvalidDefinition("unknown platform \"{$p}\" (pc | mobile)")), $platforms);

        return $this;
    }

    public function pcOnly(): static
    {
        return $this->platforms(Platform::Pc);
    }

    public function mobileOnly(): static
    {
        return $this->platforms(Platform::Mobile);
    }

    protected function visible(RenderContext $rc): bool
    {
        return $rc->onPlatform($this->platforms) && $rc->allows($this->permissions);
    }

    /** @return array<string, mixed>|null null = removed for this user / client */
    abstract public function toArray(RenderContext $rc): ?array;

    /**
     * @param iterable<Node> $nodes
     * @return list<array<string, mixed>>
     */
    protected static function render(iterable $nodes, RenderContext $rc): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($a = $n->toArray($rc)) !== null) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /** Drops null values (descriptions stay small for mini programs). */
    protected static function compact(array $a): array
    {
        return array_filter($a, static fn ($v): bool => $v !== null);
    }

    protected static function name(string $name, string $where): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*$/', $name) !== 1) {
            throw new InvalidDefinition("{$where}: invalid field name \"{$name}\"");
        }

        return $name;
    }

    /** Relative API path (may contain {placeholders}); absolute URLs are not allowed. */
    protected static function path(string $api, string $where): string
    {
        if (!str_starts_with($api, '/') || str_starts_with($api, '//') || preg_match('/\s/', $api) === 1) {
            throw new InvalidDefinition("{$where}: api must be a relative path starting with \"/\", got \"{$api}\"");
        }

        return \Loongs\Render\Support\Placeholder::assert($api, $where);
    }

    protected static function spanOf(int $span, string $where, int $max = 24): int
    {
        if ($span < 1 || $span > $max) {
            throw new InvalidDefinition("{$where}: span must be 1..{$max}, got {$span}");
        }

        return $span;
    }

    /** @param array<string, mixed> $params */
    protected static function params(array $params, string $where): array
    {
        foreach ($params as $k => $v) {
            if (!is_string($k) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k) !== 1) {
                throw new InvalidDefinition("{$where}: invalid parameter name \"{$k}\"");
            }
            if (!is_scalar($v) && $v !== null) {
                throw new InvalidDefinition("{$where}: parameter \"{$k}\" must be scalar or a \"{field}\" template");
            }
            if (is_string($v)) {
                \Loongs\Render\Support\Placeholder::assert($v, "{$where} params.{$k}");
            }
        }

        return $params;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    protected static function listOf(array $items, string $class, string $where): array
    {
        foreach ($items as $i) {
            $i instanceof $class || throw new InvalidDefinition("{$where}: items must be " . substr((string) strrchr($class, '\\'), 1));
        }

        return array_values($items);
    }
}
