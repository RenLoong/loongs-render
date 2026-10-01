<?php

declare(strict_types=1);

namespace Loongs\Render;

use Loongs\Render\Builder\Form;

/** Base of form pages (page_type: form). */
abstract class FormPage extends Page
{
    final public static function type(): PageType
    {
        return PageType::Form;
    }

    abstract public function build(PageContext $ctx): Form;
}
