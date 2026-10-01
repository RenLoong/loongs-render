<?php

declare(strict_types=1);

namespace Loongs\Render\Exception;

/** Page code outside [a-z0-9._-]. */
class InvalidPageCode extends InvalidDefinition
{
}
