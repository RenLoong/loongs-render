<?php

declare(strict_types=1);

namespace Loongs\Render\Policy;

use Loongs\Render\Contract\FieldPolicy;
use Loongs\Render\FieldAccess;
use Loongs\Render\PageContext;

/** Static map "resource.field" => FieldAccess; unlisted fields are editable. */
final class FieldMap implements FieldPolicy
{
    /** @param array<string, FieldAccess> $map */
    public function __construct(private readonly array $map = [])
    {
    }

    public function access(string $resource, string $field, PageContext $ctx): FieldAccess
    {
        return $this->map["{$resource}.{$field}"] ?? FieldAccess::Editable;
    }
}
