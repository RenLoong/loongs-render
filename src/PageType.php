<?php

declare(strict_types=1);

namespace Loongs\Render;

/** Page type declared by menu.json `page_type` and returned by the page class's builder. */
enum PageType: string
{
    case Dashboard = 'dashboard';
    case Form = 'form';
    case Table = 'table';
    case Detail = 'detail';
    case Custom = 'custom';
}
