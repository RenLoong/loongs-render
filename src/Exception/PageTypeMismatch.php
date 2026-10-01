<?php

declare(strict_types=1);

namespace Loongs\Render\Exception;

/** Builder type ≠ page class type or ≠ menu.json page_type. */
class PageTypeMismatch extends RenderException
{
}
