<?php

declare(strict_types=1);

namespace Fixture\Pages;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Dashboard;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Chart;
use Loongs\Render\DashboardPage;
use Loongs\Render\PageContext;

#[AsPage('admin.overview')]
final class OverviewPage extends DashboardPage
{
    public function build(PageContext $ctx): Dashboard
    {
        return Dashboard::make('平台概览')->blocks([
            Block::stat('租户总数', '/admin/api/stats/tenants', 'total')->trend('mom')->format('{value|thousands}')->span(6)->refresh(60),
            Chart::line('近30天开通')
                ->api('/admin/api/stats/opens')
                ->x('date', type: 'time', format: 'MM-dd')
                ->series('count', '开通数', color: '#2563eb', area: true, smooth: true)
                ->series('closed', '停用数', color: '#ef4444', dashed: true, axis: 'right')
                ->yAxis('left', min: 0, format: '{value}')
                ->yAxis('right', min: 0, format: '{value}%')
                ->legend('top')->tooltip(shared: true, format: '{series}: {value|thousands}')
                ->markLine(100, '目标', color: '#16a34a')
                ->markArea('2026-09-01', '2026-09-07', '活动期')
                ->palette(['#2563eb', '#f59e0b', '#10b981'])
                ->renderer('fl_chart', ['lineWidth' => 2, 'showDots' => false])
                ->renderer('ucharts', ['padding' => [15, 15, 0, 5]])
                ->height(320)->span(16)->id('opens'),
            Chart::donut('套餐占比')->api('/admin/api/stats/plans')->x('name')->series('count')->labels(true, '{name} {percent|percent:1}')->span(8)->can('stats:plan'),
            Block::rank('套餐排行', '/admin/api/stats/plans', 'name', 'count')->span(8),
            Chart::custom('heatmap', ['api' => '/admin/api/stats/heat', 'cell' => 12], '活跃热力')->span(16)->pcOnly(),
            Block::shortcuts([
                Action::navigate('开通租户', '/tenant/create')->icon('plus')->can('tenant:create'),
                Action::refresh('刷新图表', 'opens'),
            ])->span(24),
            Block::card('系统', [Block::alert('Redis 未配置持久化', 'warning')->can('system:ops')]),
        ]);
    }
}
