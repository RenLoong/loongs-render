<?php

declare(strict_types=1);

namespace Loongs\Render\Exception;

/** The client only understands an older description schema (X-Render-Schema) → ask to upgrade. */
class SchemaNotSupported extends RenderException
{
}
