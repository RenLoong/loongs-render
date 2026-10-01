<?php

declare(strict_types=1);

namespace Loongs\Render\Exception;

/** A page / component definition breaks the description rules (bad name, unknown option, invalid template…). */
class InvalidDefinition extends RenderException
{
}
