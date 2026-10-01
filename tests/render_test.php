<?php

declare(strict_types=1);

/**
 * loongs/render smoke tests (plain PHP, no PHPUnit):  php tests/render_test.php
 * Uses vendor/autoload.php when present, else a PSR-4 loader for src/ and the fixtures.
 */

use Fixture\AdminStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Form;
use Loongs\Render\Builder\Table;
use Loongs\Render\ChartType;
use Loongs\Render\ClientEnv;
use Loongs\Render\ClientInfo;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Chart;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\ComponentCatalog;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\Exception\InvalidPageCode;
use Loongs\Render\Exception\PageForbidden;
use Loongs\Render\Exception\PageNotFound;
use Loongs\Render\Exception\PageTypeMismatch;
use Loongs\Render\Exception\PlatformNotSupported;
use Loongs\Render\Exception\SchemaNotSupported;
use Loongs\Render\FieldAccess;
use Loongs\Render\Http\CacheKey;
use Loongs\Render\Http\Etag;
use Loongs\Render\PageCode;
use Loongs\Render\PageContext;
use Loongs\Render\PageRegistry;
use Loongs\Render\PageType;
use Loongs\Render\Platform;
use Loongs\Render\Policy\CodeList;
use Loongs\Render\Policy\FieldMap;
use Loongs\Render\RenderContext;
use Loongs\Render\Schema;
use Loongs\Render\Support\Options;
use Loongs\Render\Support\Placeholder;
use Loongs\Render\Validation\SchemaValidator;

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
if (is_file("{$root}/vendor/autoload.php")) {
    require "{$root}/vendor/autoload.php";
}
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Loongs\\Render\\' => "{$root}/src/", 'Fixture\\Pages\\' => __DIR__ . '/fixtures/Pages/', 'Fixture\\' => __DIR__ . '/fixtures/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

$passed = 0;
$failed = 0;
function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $r = $fn();
        if ($r === false) {
            throw new RuntimeException('returned false');
        }
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
function eq(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($what !== '' ? "{$what}: " : '') . 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function ok(bool $cond, string $what): void
{
    if (!$cond) {
        throw new RuntimeException("assertion failed: {$what}");
    }
}
/** @param class-string<Throwable> $class */
function throws(string $class, callable $fn, ?string $contains = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("message \"{$e->getMessage()}\" lacks \"{$contains}\"");
        }

        return;
    }
    throw new RuntimeException("expected {$class}, nothing thrown");
}
function valid(array $desc): void
{
    static $v = null;
    $v ??= new SchemaValidator();
    $errors = $v->validate($desc);
    if ($errors !== []) {
        throw new RuntimeException('schema: ' . implode('; ', array_slice($errors, 0, 5)));
    }
}
function rc(?ClientInfo $client = null, ?array $codes = null, array $fields = []): RenderContext
{
    return new RenderContext(new PageContext($client ?? ClientInfo::pc()), new CodeList($codes ?? ['*']), new FieldMap($fields));
}
/** @return list<string> */
function labels(array $actions): array
{
    return array_map(static fn (array $a): string => $a['label'], $actions);
}
function fields(array $sections): array
{
    return array_merge(...array_map(static fn (array $s): array => array_column($s['fields'], 'name'), $sections));
}

echo 'loongs/render tests — PHP ' . PHP_VERSION . "\n";

$registry = new PageRegistry();
$found = $registry->discover(__DIR__ . '/fixtures/Pages', 'Fixture\\Pages');
$all = new CodeList(['*']);
$fieldsPolicy = new FieldMap(['admins.email' => FieldAccess::Hidden, 'admins.mobile' => FieldAccess::Masked, 'admins.salary' => FieldAccess::Readonly]);
$pcCtx = new PageContext(ClientInfo::pc());
$mobileCtx = new PageContext(ClientInfo::mobile(ClientEnv::MpWeixin));

// ---------------------------------------------------------------- basics
t('page codes [a-z0-9._-]', function (): void {
    foreach (['admin.system.admins', 'a', '0-x_y.z', 'shop.order-items'] as $c) {
        ok(PageCode::isValid($c), $c);
    }
    foreach (['', 'Admin.x', 'a/b', 'a b', '.a', '-a', 'a?b', 'é', str_repeat('a', 129)] as $c) {
        ok(!PageCode::isValid($c), "invalid {$c}");
    }
    throws(InvalidPageCode::class, fn () => PageCode::assert('Admin/x'), '[a-z0-9._-]');
});

t('client headers: platform, env, schema, components', function (): void {
    $c = ClientInfo::fromHeaders([]);
    eq(Platform::Pc, $c->platform);
    eq(null, $c->env);
    eq(Schema::VERSION, $c->schema);
    eq('pc', $c->cacheKey());
    $m = ClientInfo::fromHeaders(['x-client-platform' => 'MOBILE', 'X-Client-Env' => ['mp-weixin'], 'X-Render-Schema' => '1', 'X-Render-Components' => 'abc123']);
    eq(Platform::Mobile, $m->platform);
    eq(ClientEnv::MpWeixin, $m->env);
    eq('abc123', $m->componentsHash);
    eq('mobile.mp-weixin', $m->cacheKey());
    ok($m->isMobile, 'isMobile hook');
    eq('pc', ClientInfo::fromHeaders(['X-Client-Platform' => 'tv', 'X-Client-Env' => 'app'])->cacheKey(), 'unknown platform → pc, env ignored on pc');
    eq(16, strlen(ComponentCatalog::hash()));
});

t('placeholders: names, filters, literals, render', function (): void {
    eq(['id', 'dept.name'], Placeholder::names('/x/{id}?d={dept.name}&{{raw}}'));
    eq('/x/7?d=研发&{raw}', Placeholder::render('/x/{id}?d={dept.name}&{{raw}}', ['id' => 7, 'dept' => ['name' => '研发']]));
    Placeholder::assert('{value|money:CNY} / {x|date:MM-dd} {value|percent:1}', 'fmt', ['value', 'x'], true);
    throws(InvalidDefinition::class, fn () => Placeholder::assert('{value|thousands}', 'label'), 'filter');
    throws(InvalidDefinition::class, fn () => Placeholder::assert('{a.b()}', 'x'), 'malformed');
    throws(InvalidDefinition::class, fn () => Placeholder::assert('{secret}', 'fmt', ['value']), 'not allowed');
    throws(InvalidDefinition::class, fn () => Placeholder::assert('{value|money:cny}', 'fmt', null, true), 'bad argument');
    throws(InvalidDefinition::class, fn () => Placeholder::assert('{value|eval}', 'fmt', null, true));
});

t('enum → options with label/color', function (): void {
    eq([['value' => 1, 'label' => '正常', 'color' => 'success'], ['value' => 0, 'label' => '禁用', 'color' => '#ef4444']], Options::from(AdminStatus::class, 'x'));
    eq([['value' => 'a', 'label' => 'A']], Options::from(['a' => 'A'], 'x'));
    eq([['value' => PageType::Form->value, 'label' => 'Form']], array_slice(Options::fromEnum(PageType::class), 1, 1));
    throws(InvalidDefinition::class, fn () => Options::from('NoSuchEnum', 'x'), 'not a backed enum');
});

// ---------------------------------------------------------------- registry
t('discover: #[AsPage] classes, abstract/helpers skipped', function () use ($found, $registry): void {
    eq(['admin.overview', 'admin.system.admin-form', 'admin.system.admins', 'admin.system.role-permission', 'admin.tenant.detail'], array_keys($registry->definitions()));
    eq(5, count($found));
    $d = $registry->get('admin.system.admin-form');
    eq(PageType::Form, $d->type);
    eq(['system:admin:create', 'system:admin:edit'], $d->permissions);
    eq('v3', $d->version);
    eq(8, strlen($registry->get('admin.overview')->version), 'file hash version');
});

t('discoverApps: apps/<App>/Pages incl. sub-namespaces', function (): void {
    $r = new PageRegistry();
    $defs = $r->discoverApps(__DIR__ . '/fixtures/apps');
    eq(['admin.home', 'shop.orders'], array_map(static fn ($d) => $d->code, $defs));
    eq('App\\Shop\\Pages\\Order\\OrdersPage', $r->get('shop.orders')->class);
});

t('duplicate page code names both classes', function () use ($registry): void {
    throws(InvalidDefinition::class, fn () => $registry->discover(__DIR__ . '/fixtures/dup', 'Fixture\\Dup'), 'Fixture\\Pages\\OverviewPage and Fixture\\Dup\\DupPage');
});

t('invalid page code / non-page class rejected', function (): void {
    $r = new PageRegistry();
    require_once __DIR__ . '/fixtures/badcode/BadCodePage.php';
    throws(InvalidPageCode::class, fn () => $r->register(Fixture\Bad\BadCodePage::class));
    throws(InvalidDefinition::class, fn () => $r->register(Fixture\Pages\Helper::class), 'subclass');
    throws(InvalidDefinition::class, fn () => $r->register(Fixture\Pages\AbstractBase::class), 'concrete');
});

t('unknown page → PageNotFound', fn () => throws(PageNotFound::class, fn () => $registry->render('admin.nope', $pcCtx)));

t('page permission → PageForbidden (403), any-of', function () use ($registry, $pcCtx): void {
    throws(PageForbidden::class, fn () => $registry->render('admin.system.admins', $pcCtx, new CodeList(['other'])));
    eq('admin.system.admins', $registry->render('admin.system.admins', $pcCtx, new CodeList(['system:admin']))['page']);
    eq('form', $registry->render('admin.system.admin-form', $pcCtx, new CodeList(['system:admin:edit']))['type'], 'any-of');
});

t('type mismatch: builder ≠ class type, ≠ menu page_type', function () use ($registry, $pcCtx): void {
    throws(PageTypeMismatch::class, fn () => $registry->render('admin.system.admins', $pcCtx, expected: 'form'), 'expected form');
    $registry->assertType('admin.system.admins', 'table');
    throws(PageTypeMismatch::class, fn () => $registry->assertType('admin.system.admins', PageType::Detail));
    $r = new PageRegistry();
    $r->discover(__DIR__ . '/fixtures/bad', 'Fixture\\Bad');
    throws(PageTypeMismatch::class, fn () => $r->render('bad.mismatch', new PageContext()), 'builds form');
    throws(PageTypeMismatch::class, fn () => $r->assertType('bad.mismatch', 'table'), 'MismatchPage.php');
});

t('schema version negotiation', function () use ($registry): void {
    throws(SchemaNotSupported::class, fn () => $registry->render('admin.overview', new PageContext(new ClientInfo(schema: 0))), 'upgrade');
    eq(1, $registry->render('admin.overview', new PageContext(new ClientInfo(schema: 2)))['schema']);
});

t('platform: pcOnly page → PlatformNotSupported on mobile', function () use ($registry, $mobileCtx, $pcCtx, $all): void {
    throws(PlatformNotSupported::class, fn () => $registry->render('admin.system.role-permission', $mobileCtx, $all));
    $d = $registry->render('admin.system.role-permission', $pcCtx, $all);
    eq(['pc'], $d['meta']['platforms']);
    eq('role-permission-editor', $d['body']['component']);
    valid($d);
});

// ---------------------------------------------------------------- table
t('table PC full: shell, columns, actions, policy', function () use ($registry, $pcCtx, $all, $fieldsPolicy): void {
    $d = $registry->render('admin.system.admins', $pcCtx, $all, $fieldsPolicy, PageType::Table);
    valid($d);
    eq(['schema', 'page', 'type', 'title', 'version', 'breadcrumb', 'body', 'meta'], array_keys($d));
    eq(['系统管理', '管理员'], $d['breadcrumb']);
    eq(['platforms' => ['pc', 'mobile'], 'platform' => 'pc'], $d['meta']);
    ok(preg_match('/^[0-9a-f]{12}$/', $d['version']) === 1, 'version hash');
    $b = $d['body'];
    eq(['username', 'mobile', 'status', 'created_at'], array_column($b['columns'], 'field'), 'email hidden by policy');
    eq(true, $b['columns'][1]['masked'], 'mobile masked');
    eq([['value' => 1, 'label' => '正常', 'color' => 'success'], ['value' => 0, 'label' => '禁用', 'color' => '#ef4444']], $b['columns'][2]['options']);
    eq(['新增', '批量禁用'], labels($b['toolbar']));
    eq(true, $b['selection'], 'batch → selection');
    eq(['page' => 'admin.system.admin-form', 'params' => ['id' => '{id}'], 'mode' => 'drawer'], array_intersect_key($b['rowActions'][0], ['page' => 1, 'params' => 1, 'mode' => 1]));
    eq(['label' => '删除', 'action' => 'request', 'method' => 'DELETE', 'api' => '/admin/api/admins/{id}', 'confirm' => '确定删除 {username}？', 'danger' => true, 'then' => 'refresh'], $b['rowActions'][1]);
    eq(['field' => 'id', 'order' => 'desc'], $b['defaultSort']);
    eq([20, 50, 100], $b['pageSizes']);
});

t('table: ->can() actions removed, nothing leaks', function () use ($registry, $pcCtx): void {
    $d = $registry->render('admin.system.admins', $pcCtx, new CodeList(['system:admin', 'system:admin:edit']));
    $b = $d['body'];
    eq(['批量禁用'], labels($b['toolbar']));
    eq(['编辑'], labels($b['rowActions']));
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    ok(!str_contains($json, '删除') && !str_contains($json, '新增') && !str_contains($json, 'system:admin:delete'), 'no denied labels / codes in output');
    $none = $registry->render('admin.system.admins', $pcCtx, new CodeList(['system:admin']))['body'];
    eq([], $none['toolbar']);
    eq(false, $none['selection'], 'no batch permission → no selection');
    valid($d);
});

t('table mobile: only mobile columns, no selection / batch, no fixed/width', function () use ($registry, $mobileCtx, $all, $fieldsPolicy): void {
    $d = $registry->render('admin.system.admins', $mobileCtx, $all, $fieldsPolicy);
    valid($d);
    eq(['platforms' => ['pc', 'mobile'], 'platform' => 'mobile', 'env' => 'mp-weixin'], $d['meta']);
    $b = $d['body'];
    eq(['username', 'status'], array_column($b['columns'], 'field'));
    ok(!isset($b['columns'][0]['fixed']) && !isset($b['columns'][0]['width']), 'fixed/width dropped');
    eq(false, $b['selection']);
    eq(['新增'], labels($b['toolbar']), 'batchRequest unsupported on mobile');
});

t('table mobile fallback: no ->mobile() column → first 3', function (): void {
    $r = new PageRegistry();
    $r->discoverApps(__DIR__ . '/fixtures/apps');
    $d = $r->render('shop.orders', new PageContext(ClientInfo::mobile()));
    eq(['no', 'amount', 'buyer'], array_column($d['body']['columns'], 'field'));
    valid($d);
});

t('version / ETag differ per platform and permission, stable otherwise', function () use ($registry, $pcCtx, $mobileCtx, $all): void {
    $a = $registry->render('admin.system.admins', $pcCtx, $all);
    $b = $registry->render('admin.system.admins', $pcCtx, $all);
    $c = $registry->render('admin.system.admins', $mobileCtx, $all);
    $d = $registry->render('admin.system.admins', $pcCtx, new CodeList(['system:admin']));
    eq($a['version'], $b['version']);
    ok($a['version'] !== $c['version'] && $a['version'] !== $d['version'], 'differs');
    $etag = Etag::of($a);
    eq('"' . $a['version'] . '"', $etag);
    ok(Etag::matches($etag, $etag) && Etag::matches('W/' . $etag . ', "zzz"', $etag) && Etag::matches('*', $etag), 'If-None-Match');
    ok(!Etag::matches('"zzz"', $etag) && !Etag::matches(null, $etag) && !Etag::matches('', $etag), 'no match');
});

t('cache key: code + platform + fingerprint + version', function () use ($registry): void {
    $def = $registry->get('admin.system.admin-form');
    $codes = new CodeList(['system:admin:edit']);
    $k = CacheKey::for($def, new PageContext(ClientInfo::mobile(ClientEnv::App)), $codes);
    eq('render:s1:admin.system.admin-form:mobile.app:' . $codes->fingerprint(new PageContext()) . ':v3', $k);
    ok(str_starts_with($k, CacheKey::prefix('admin.system.admin-form')), 'prefix');
    ok(CacheKey::of($def, ClientInfo::pc(), 'a') !== CacheKey::of($def, ClientInfo::pc(), 'b'), 'fingerprint');
});

// ---------------------------------------------------------------- form
t('form PC: sections, submit per mode, policy readonly/masked/hidden', function () use ($registry, $all): void {
    $policy = new FieldMap(['admins.salary' => FieldAccess::Readonly, 'admins.mobile' => FieldAccess::Masked, 'admins.password' => FieldAccess::Hidden]);
    $d = $registry->render('admin.system.admin-form', new PageContext(params: ['id' => 5]), $all, $policy, 'form');
    valid($d);
    eq('编辑管理员', $d['title']);
    $b = $d['body'];
    eq(['create' => ['method' => 'POST', 'api' => '/admin/api/admins'], 'update' => ['method' => 'PUT', 'api' => '/admin/api/admins/{id}']], $b['submit']);
    eq(2, $b['columns']);
    eq(['username', 'mobile', 'salary', 'status', 'intro', 'role_ids'], fields($b['sections']));
    $f = array_column($b['sections'][0]['fields'], null, 'name');
    eq(true, $f['salary']['readonly']);
    eq(true, $f['mobile']['readonly'] && $f['mobile']['masked']);
    eq(true, $f['username']['required']);
    eq([['rule' => 'minLength', 'value' => 3], ['rule' => 'maxLength', 'value' => 32]], $f['username']['rules']);
    eq([['rule' => 'regex', 'value' => '^1\d{10}$', 'message' => '手机号格式不正确']], $f['mobile']['rules']);
    eq(1, $f['status']['default']);
    eq(['api' => '/admin/api/roles/options', 'searchable' => true], $b['sections'][1]['fields'][0]['optionsApi']);
    eq([['label' => '保存', 'action' => 'submit', 'primary' => true, 'then' => 'close'], ['label' => '取消', 'action' => 'close']], $b['actions']);
});

t('form: section ->can() removed; mobile single column, richText dropped, no span', function () use ($registry): void {
    $d = $registry->render('admin.system.admin-form', new PageContext(ClientInfo::mobile()), new CodeList(['system:admin:create']));
    valid($d);
    eq('新增管理员', $d['title']);
    eq(1, $d['body']['columns']);
    eq(1, count($d['body']['sections']), 'role section removed');
    eq(['username', 'mobile', 'salary', 'password', 'status'], fields($d['body']['sections']));
    ok(!isset($d['body']['sections'][0]['fields'][0]['span']), 'span dropped');
    eq(['field' => 'type', 'in' => ['local']], $d['body']['sections'][0]['fields'][3]['visibleWhen']);
});

t('action visibleWhen / hiddenWhen (row conditions, display hints)', function (): void {
    $a = Action::request('删除', 'DELETE', '/admin/api/admins/{id}')->hiddenWhen('is_super', 'in', [true, 1])->toArray(rc());
    eq(['field' => 'is_super', 'in' => [true, 1]], $a['hiddenWhen']);
    ok(!isset($a['visibleWhen']), 'no visibleWhen');
    $b = Action::openForm('审核', 'admin.system.admin-form', ['id' => '{id}'])->visibleWhen('status', 'eq', 0)->toArray(rc());
    eq(['field' => 'status', 'eq' => 0], $b['visibleWhen']);
    eq(['field' => 'mobile', 'notEmpty' => true], Action::request('解绑', 'DELETE', '/x/{id}')->visibleWhen('mobile', 'notEmpty')->toArray(rc())['visibleWhen']);
    throws(InvalidDefinition::class, fn () => Action::close()->hiddenWhen('a', 'script', 'x'), 'hiddenWhen op must be eq | in | notEmpty');
    throws(InvalidDefinition::class, fn () => Action::close()->visibleWhen('a', 'in', 'x'), 'visibleWhen in needs a list');
    throws(InvalidDefinition::class, fn () => Action::close()->visibleWhen('a b', 'notEmpty'), 'invalid field name');
    $page = Table::make('T')->api('/admin/api/x')->columns([Column::text('name', '名称')])
        ->rowActions([Action::request('删除', 'DELETE', '/admin/api/x/{id}')->hiddenWhen('locked', 'eq', true)])->toArray(rc());
    $v = new SchemaValidator();
    eq([], $v->validate(['schema' => Schema::VERSION, 'page' => 'x.y', 'type' => 'table', 'title' => 'T', 'version' => 'abcdef012345', 'breadcrumb' => [], 'body' => $page, 'meta' => ['platforms' => ['pc', 'mobile'], 'platform' => 'pc']]));
    $bad = $page;
    $bad['rowActions'][0]['hiddenWhen'] = ['field' => 'locked', 'script' => 'x'];
    ok($v->validate(['schema' => Schema::VERSION, 'page' => 'x.y', 'type' => 'table', 'title' => 'T', 'version' => 'abcdef012345', 'breadcrumb' => [], 'body' => $bad, 'meta' => ['platforms' => ['pc', 'mobile'], 'platform' => 'pc']]) !== [], 'unknown condition key rejected');
});

t('form validation errors', function (): void {
    throws(InvalidDefinition::class, fn () => Form::make('x')->fields([Field::text('a', 'A')])->toArray(rc()), 'submit');
    throws(InvalidDefinition::class, fn () => Form::make('x')->submit('DELETE', '/x'), 'POST | PUT | PATCH');
    throws(InvalidDefinition::class, fn () => Form::make('x')->submit('POST', '/x')->fields([Field::text('a', 'A'), Field::text('a', 'B')])->toArray(rc()), 'duplicate field a');
    throws(InvalidDefinition::class, fn () => Field::text('a', 'A')->options(['x' => 'X']), 'has no options');
    throws(InvalidDefinition::class, fn () => Field::text('a', 'A')->regex('(unclosed'), 'invalid regex');
    throws(InvalidDefinition::class, fn () => Field::text('a', 'A')->visibleWhen('b', 'script', 'x'), 'eq | in | notEmpty');
    throws(InvalidDefinition::class, fn () => Field::text('a b', 'A'), 'invalid field name');
    throws(InvalidDefinition::class, fn () => Field::text('a', 'A')->span(25), 'span');
    eq([['value' => 1, 'label' => '北京', 'children' => [['value' => 11, 'label' => '朝阳']]]],
        Field::cascader('region', '地区')->options([['value' => 1, 'label' => '北京', 'children' => [['value' => 11, 'label' => '朝阳']]]])->toArray(rc())['options']);
    eq(true, Field::json('raw', '原始数据')->toArray(rc())['readonly'], 'json is read-only');
});

// ---------------------------------------------------------------- dashboard / charts
t('dashboard PC: blocks, chart spec, block ->can()', function () use ($registry, $pcCtx): void {
    $d = $registry->render('admin.overview', $pcCtx, new CodeList(['stats:plan']));
    valid($d);
    $b = $d['body'];
    eq(24, $b['grid']);
    eq(['stat', 'chart', 'chart', 'rank', 'chart', 'shortcuts'], array_column($b['blocks'], 'component'), 'alert-only card dropped (no system:ops)');
    eq(['component' => 'stat', 'title' => '租户总数', 'span' => 6, 'refresh' => 60, 'api' => '/admin/api/stats/tenants', 'field' => 'total', 'trend' => 'mom', 'format' => '{value|thousands}'], $b['blocks'][0]);
    $line = $b['blocks'][1];
    eq('line', $line['chart']);
    eq(['field' => 'date', 'type' => 'time', 'format' => 'MM-dd'], $line['x']);
    eq([['field' => 'count', 'name' => '开通数', 'color' => '#2563eb', 'area' => true, 'smooth' => true],
        ['field' => 'closed', 'name' => '停用数', 'color' => '#ef4444', 'dashed' => true, 'axis' => 'right']], $line['series']);
    eq(['left' => ['min' => 0, 'format' => '{value}'], 'right' => ['min' => 0, 'format' => '{value}%']], $line['yAxis']);
    eq('top', $line['legend']);
    eq(['shared' => true, 'format' => '{series}: {value|thousands}'], $line['tooltip']);
    eq([['value' => 100, 'label' => '目标', 'axis' => 'left', 'color' => '#16a34a', 'dashed' => true]], $line['markLines']);
    eq([['from' => '2026-09-01', 'to' => '2026-09-07', 'label' => '活动期', 'axis' => 'x']], $line['markAreas']);
    eq(['#2563eb', '#f59e0b', '#10b981'], $line['palette']);
    eq(['fl_chart' => ['lineWidth' => 2, 'showDots' => false], 'ucharts' => ['padding' => [15, 15, 0, 5]]], $line['renderer']);
    eq(320, $line['height']);
    eq('opens', $line['id']);
    eq(['show' => true, 'format' => '{name} {percent|percent:1}'], $b['blocks'][2]['labels']);
    eq(['component' => 'chart', 'title' => '活跃热力', 'span' => 16, 'chart' => 'custom', 'name' => 'heatmap', 'config' => ['api' => '/admin/api/stats/heat', 'cell' => 12]], $b['blocks'][4]);
    eq([['title' => '刷新图表', 'action' => ['action' => 'refresh', 'target' => 'opens']]], $b['blocks'][5]['items'], 'shortcut without tenant:create');
});

t('dashboard: all codes → card+alert; donut hidden without code', function () use ($registry, $pcCtx, $all): void {
    $b = $registry->render('admin.overview', $pcCtx, $all)['body'];
    eq('card', $b[ 'blocks'][6]['component']);
    eq([['component' => 'alert', 'type' => 'warning', 'message' => 'Redis 未配置持久化']], $b['blocks'][6]['blocks']);
    eq(2, count($b['blocks'][5]['items']));
    eq(['title' => '开通租户', 'icon' => 'plus', 'action' => ['action' => 'navigate', 'path' => '/tenant/create']], $b['blocks'][5]['items'][0]);
    $none = $registry->render('admin.overview', $pcCtx, new CodeList([]))['body'];
    ok(!in_array('套餐占比', array_column($none['blocks'], 'title'), true), 'donut removed');
});

t('dashboard mobile: stacked (no span), pcOnly chart removed', function () use ($registry, $mobileCtx, $all): void {
    $d = $registry->render('admin.overview', $mobileCtx, $all);
    valid($d);
    ok(array_filter($d['body']['blocks'], static fn ($b) => isset($b['span'])) === [], 'no span');
    ok(!in_array('custom', array_column($d['body']['blocks'], 'chart'), true), 'pcOnly custom chart gone');
});

t('chart types + validation', function (): void {
    foreach (ChartType::cases() as $type) {
        if ($type === ChartType::Custom) {
            continue;
        }
        $c = Chart::of($type, $type->value)->api('/s');
        $type !== ChartType::Gauge && $c->x('d');
        $c->series('v', type: $type === ChartType::Combo ? 'bar' : null);
        eq($type->value, $c->toArray(rc())['chart']);
    }
    $combo = Chart::combo('c')->api('/s')->x('d')->series('a', type: 'bar')->series('b', type: 'line', axis: 'right')->yAxis('right', max: 100)->toArray(rc());
    eq(['bar', 'line'], array_column($combo['series'], 'type'));
    throws(InvalidDefinition::class, fn () => Chart::pie('p')->api('/s')->x('n')->series('a')->series('b')->toArray(rc()), 'exactly one series');
    throws(InvalidDefinition::class, fn () => Chart::combo('c')->api('/s')->x('d')->series('a')->toArray(rc()), 'needs type');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->api('/s')->x('d')->series('a', type: 'bar')->toArray(rc()), 'only for combo');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->x('d')->series('a')->toArray(rc()), 'api() is required');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->api('/s')->x('d')->toArray(rc()), 'at least one series');
    throws(InvalidDefinition::class, fn () => Chart::pie('p')->api('/s')->x('n')->series('a', axis: 'right')->toArray(rc()), 'no right axis');
    throws(InvalidDefinition::class, fn () => Chart::pie('p')->api('/s')->x('n')->series('a')->markLine(1)->toArray(rc()), 'cartesian');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->api('/s')->x('d')->series('a')->markLine(1, axis: 'right')->toArray(rc()), 'right axis');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->series('a')->series('a'), 'duplicate series');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->series('a', color: 'red;background:url(x)'), 'color');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->tooltip(format: '{value|js:alert(1)}'), 'filter');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->tooltip(format: '{password}'), 'not allowed');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->x('d', 'time', 'function(){}'), 'date format');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->api('https://evil.example/x'), 'relative path');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->height(5000), 'height');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->yAxis('left', 10, 1), 'min must be < max');
    throws(InvalidDefinition::class, fn () => Chart::of('custom'), 'Chart::custom');
    throws(InvalidDefinition::class, fn () => Chart::custom('Heat Map'), '[a-z]');
    throws(InvalidDefinition::class, fn () => Chart::custom('heat', ['cb' => new stdClass()]), 'plain JSON');
    eq('custom', Chart::custom('heat')->toArray(rc())['chart']);
});

t('chart renderer passthrough whitelist', function (): void {
    throws(InvalidDefinition::class, fn () => Chart::line('l')->renderer('echarts', ['a' => 1]), 'unknown renderer');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->renderer('fl_chart', ['onTap' => 'x']), 'not whitelisted');
    throws(InvalidDefinition::class, fn () => Chart::line('l')->renderer('ucharts', ['padding' => [[1]]]), 'scalar');
    Chart::allowRendererOption('ucharts', 'xAxisFontSize');
    eq(['ucharts' => ['xAxisFontSize' => 10]], Chart::line('l')->api('/s')->x('d')->series('v')->renderer('ucharts', ['xAxisFontSize' => 10])->toArray(rc())['renderer']);
});

t('catalog: unsupported chart type removed for a client', function (): void {
    $cat = (new ComponentCatalog())->withUnsupported('mobile.mp-weixin', 'chart:radar');
    $c = Chart::radar('r')->api('/s')->x('k')->series('v');
    $mp = new RenderContext(new PageContext(ClientInfo::mobile(ClientEnv::MpWeixin)), catalog: $cat);
    $app = new RenderContext(new PageContext(ClientInfo::mobile(ClientEnv::App)), catalog: $cat);
    eq(null, $c->toArray($mp));
    eq('radar', $c->toArray($app)['chart']);
});

// ---------------------------------------------------------------- detail
t('detail: header, tabs, descriptions, embedded table, tab ->can()', function () use ($registry, $pcCtx): void {
    $d = $registry->render('admin.tenant.detail', $pcCtx, new CodeList(['tenant:view']), new FieldMap(['tenants.balance' => FieldAccess::Masked]));
    valid($d);
    $b = $d['body'];
    eq('/admin/api/tenants/{id}', $b['load']);
    eq('{name}', $b['header']['title']);
    eq('tag', $b['header']['tags'][0]['component']);
    ok(!isset($b['header']['actions']), 'edit action removed');
    eq(['基本信息', '套餐记录'], array_column($b['tabs'], 'title'), 'logs tab removed');
    $desc = $b['tabs'][0]['blocks'][0];
    eq(3, $desc['columns']);
    eq(true, $desc['items'][2]['masked']);
    eq('{value|money:CNY}', $desc['items'][2]['format']);
    eq(['field' => 'owner', 'label' => '负责人', 'component' => 'link', 'href' => '/system/admins?id={owner_id}', 'span' => 2], $desc['items'][3]);
    eq(['component' => 'table', 'page' => 'admin.tenant.plan-logs', 'params' => ['tenant_id' => '{id}']], $b['tabs'][1]['blocks'][0]);
});

t('detail mobile: descriptions single column, no item span', function () use ($registry, $all): void {
    $d = $registry->render('admin.tenant.detail', new PageContext(ClientInfo::mobile(ClientEnv::App)), $all);
    valid($d);
    $desc = $d['body']['tabs'][0]['blocks'][0];
    eq(1, $desc['columns']);
    ok(!isset($desc['items'][3]['span']), 'span dropped');
    eq(3, count($d['body']['tabs']));
});

// ---------------------------------------------------------------- components
t('action / column / block validation', function (): void {
    throws(InvalidPageCode::class, fn () => Action::openForm('x', 'Bad Page'));
    throws(InvalidDefinition::class, fn () => Action::openForm('x', 'a.b', [], 'popup'), 'mode');
    throws(InvalidDefinition::class, fn () => Action::request('x', 'TRACE', '/a'), 'method');
    throws(InvalidDefinition::class, fn () => Action::request('x', 'POST', '//evil.example/a'), 'relative path');
    throws(InvalidDefinition::class, fn () => Action::request('x', 'POST', '/a')->confirm('{a; drop}'), 'malformed');
    throws(InvalidDefinition::class, fn () => Action::navigate('x', '/a', ['bad key' => 1]), 'parameter name');
    throws(InvalidDefinition::class, fn () => Action::refresh()->then('eval'), 'then');
    throws(InvalidDefinition::class, fn () => Action::request('x', 'POST', '/a')->can('bad code!'), 'permission code');
    throws(InvalidDefinition::class, fn () => Column::text('a', 'A')->fixed('top'), 'left | right');
    throws(InvalidDefinition::class, fn () => Column::datetime('a', 'A')->format('{value}'), 'date pattern');
    throws(InvalidDefinition::class, fn () => Column::text('a', 'A')->format('{other}'), 'not allowed');
    throws(InvalidDefinition::class, fn () => Block::stat('s', '/a', 'v')->refresh(1), '≥ 5');
    throws(InvalidDefinition::class, fn () => Block::descriptions([Field::text('a', 'A')]), 'Column');
    throws(InvalidDefinition::class, fn () => Block::alert('m', 'fatal'), 'alert type');
    throws(InvalidDefinition::class, fn () => Table::make('t')->columns([Column::text('a', 'A')])->toArray(rc()), 'api() is required');
    throws(InvalidDefinition::class, fn () => Table::make('t')->api('/a')->columns([Column::text('a', 'A'), Column::text('a', 'B')])->toArray(rc()), 'duplicate column');
    eq(['label' => '提交', 'action' => 'request', 'method' => 'POST', 'api' => '/a/{id}', 'body' => ['ids' => ['{id}'], 'reason' => '{reason}'], 'then' => 'navigate', 'thenPath' => '/list', 'message' => '已提交 {id}'],
        Action::request('提交', 'post', '/a/{id}', ['ids' => ['{id}'], 'reason' => '{reason}'])->then('navigate', '/list')->message('已提交 {id}')->toArray(rc()));
});

t('platform restriction on nodes', function (): void {
    $col = Column::text('a', 'A')->pcOnly();
    eq(null, $col->toArray(rc(ClientInfo::mobile())));
    eq('a', $col->toArray(rc())['field']);
    eq(null, Action::refresh()->mobileOnly()->toArray(rc()));
    throws(InvalidDefinition::class, fn () => Column::text('a', 'A')->platforms('tv'), 'unknown platform');
});

t('inspect: codes + resource fields of a page', function () use ($registry): void {
    $i = $registry->inspect('admin.system.admins');
    eq('table', $i['type']);
    eq(['system:admin'], $i['permissions']);
    eq(['system:admin:create', 'system:admin:delete', 'system:admin:edit'], $i['codes']);
    eq(['admins.created_at', 'admins.email', 'admins.keyword', 'admins.mobile', 'admins.status', 'admins.username'], $i['fields']);
});

t('custom DI factory', function (): void {
    $made = [];
    $r = new PageRegistry(static function (string $class) use (&$made) {
        $made[] = $class;

        return new $class();
    });
    $r->register(Fixture\Pages\OverviewPage::class);
    $r->render('admin.overview', new PageContext());
    eq([Fixture\Pages\OverviewPage::class], $made);
});

// ---------------------------------------------------------------- JSON schema
t('JSON Schema export + validator rejects bad descriptions', function () use ($registry, $pcCtx, $all): void {
    $s = Schema::jsonSchema();
    eq('https://json-schema.org/draft/2020-12/schema', $s['$schema']);
    ok(isset($s['$defs']['chart'], $s['$defs']['tableBody'], $s['$defs']['action']), 'defs');
    json_decode(Schema::json(), true, 512, JSON_THROW_ON_ERROR);
    eq(Schema::json() . "\n", file_get_contents(dirname(__DIR__) . '/schema/page.v1.json'), 'schema/page.v1.json is up to date (bin/render-schema)');
    $good = $registry->render('admin.system.admins', $pcCtx, $all);
    $v = new SchemaValidator();
    eq([], $v->validate($good));
    $badType = $good;
    $badType['type'] = 'grid';
    eq(['/type: "grid" not in ["dashboard","form","table","detail","custom"]'], $v->validate($badType));
    $bad = $good;
    $bad['body']['columns'][0]['component'] = 'marquee';
    $bad['body']['rowActions'][0]['action'] = 'eval';
    unset($bad['body']['api']);
    $errors = $v->validate($bad);
    ok(count($errors) >= 3, 'errors: ' . implode(' | ', $errors));
    foreach (['/body/columns/0/component', '/body/rowActions/0/action', '/body: missing required "api"'] as $want) {
        ok((bool) array_filter($errors, static fn ($e) => str_starts_with($e, $want)), "{$want} in " . implode(' | ', $errors));
    }
    $bad2 = $good;
    $bad2['body']['rowActions'][1]['api'] = 'https://evil.example';
    $bad2['schema'] = 2;
    eq(2, count($v->validate($bad2)), implode(' | ', $v->validate($bad2)));
    $bad3 = $registry->render('admin.overview', $pcCtx, $all);
    unset($bad3['body']['blocks'][1]['series']);
    $bad3['body']['blocks'][1]['legend'] = 'middle';
    eq(2, count($v->validate($bad3)), implode(' | ', $v->validate($bad3)));
});

echo "\n" . ($failed === 0 ? 'ALL PASS' : 'FAILED') . " passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
