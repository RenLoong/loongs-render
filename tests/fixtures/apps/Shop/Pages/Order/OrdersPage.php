<?php

declare(strict_types=1);

namespace App\Shop\Pages\Order;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Column;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

#[AsPage('shop.orders')]
final class OrdersPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('订单')->api('/shop/api/orders')->columns([
            Column::text('no', '单号'), Column::money('amount', '金额'), Column::text('buyer', '买家'), Column::datetime('paid_at', '支付时间'),
        ]);
    }
}
