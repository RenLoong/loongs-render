<?php

declare(strict_types=1);

namespace Fixture\Bad;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Builder;
use Loongs\Render\Builder\Form;
use Loongs\Render\Page;
use Loongs\Render\PageContext;
use Loongs\Render\PageType;

/** Claims table but builds a form (page class not using TablePage). */
#[AsPage('bad.mismatch')]
final class MismatchPage extends Page
{
    public static function type(): PageType
    {
        return PageType::Table;
    }

    public function build(PageContext $ctx): Builder
    {
        return Form::make('x')->submit('POST', '/x')->fields([]);
    }
}
