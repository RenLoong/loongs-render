<?php

declare(strict_types=1);

namespace Loongs\Render\Contract;

use Loongs\Render\FieldAccess;
use Loongs\Render\PageContext;

/**
 * Host-application field permission (uuims: resource_fields + role_field, broadest role wins).
 * $resource comes from the builder's ->resource('uuims') (or a field's own ->resource()); fields
 * without a resource are not asked.
 */
interface FieldPolicy
{
    public function access(string $resource, string $field, PageContext $ctx): FieldAccess;
}
