# loongs/render

Server-driven UI for the loong-swoole stack (PHP 8.4). Pages are PHP classes. Each class builds a
**page description**, a small versioned JSON document that tells the client what the page is
(`dashboard | form | table | detail | custom`), which blocks, fields, columns and buttons it has, and
which APIs load and submit the data. The client renderers only implement page templates and
components:

- Flutter (PC): `admin/client`
- uni-app (H5 / App / WeChat mini program): `admin/mobile`

Permissions, field policies and per-platform trimming are applied on the server, so a client only
receives what the current user may see on that device. The package itself has no admin-specific
logic. Hosts plug their rules in through `PermissionResolver` and `FieldPolicy`.

The design document is `admin/RENDER.md` in the loong-swoole repository (this package is step R0).

```bash
composer require loongs/render:dev-main      # Packagist; locally via a path repo (see "Development")
```

Requirements: PHP ^8.4, ext-json. There are no other runtime dependencies.

## Layout

```
src/
  Page.php  TablePage FormPage DashboardPage DetailPage CustomPage   page base classes (type fixed per base)
  AsPage.php                 #[AsPage('admin.system.admins', permission: 'system:admin', version: null)]
  PageRegistry.php           register / discover / discoverApps, render(), assertType(), inspect()
  PageDefinition.php PageContext.php PageType.php PageCode.php
  ClientInfo.php Platform.php ClientEnv.php                       X-Client-Platform / X-Client-Env / X-Render-Schema / X-Render-Components
  Schema.php                 schema VERSION (=1) + JSON Schema (draft 2020-12) export
  ComponentCatalog.php       component names of schema 1 + what each platform / env does not support
  ChartType.php FieldAccess.php RenderContext.php (internal)
  Builder/                   Builder, Table, Form, Dashboard, Detail, Custom
  Component/                 Node, Field, Column, Action, Block, Chart, Section, Tab
  Contract/                  PermissionResolver, FieldPolicy, OptionEnum
  Policy/                    AllowAll, CodeList, FieldMap (simple implementations for tests / small hosts)
  Http/                      Etag (ETag / If-None-Match), CacheKey
  Validation/SchemaValidator.php   minimal JSON Schema validator for the exported schema
  Support/                   Placeholder ({field} templates + format filters), Options (enum → options), Color
  Exception/                 RenderException ← InvalidDefinition ← InvalidPageCode; PageNotFound, PageForbidden,
                             PageTypeMismatch, PlatformNotSupported, SchemaNotSupported
schema/page.v1.json          exported JSON Schema (regenerate: bin/render-schema > schema/page.v1.json)
tests/render_test.php        dependency-free smoke tests (+ tests/fixtures)
```

## A page

```php
namespace App\Admin\Pages;

use App\Admin\Enum\AdminStatus;          // backed enum implementing Loongs\Render\Contract\OptionEnum (label + color)
use Loongs\Render\{AsPage, PageContext, TablePage};
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\{Action, Column, Field};

#[AsPage('admin.system.admins', permission: 'system:admin')]
final class AdminsPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('管理员')
            ->breadcrumb(['系统管理', '管理员'])
            ->resource('admins')                                   // FieldPolicy resource for every field / column
            ->api('/admin/api/admins')
            ->rowKey('id')
            ->search([
                Field::text('keyword', '用户名/姓名'),
                Field::select('status', '状态')->options(AdminStatus::class),
                Field::dateRange('created_at', '创建时间'),
            ])
            ->columns([
                Column::text('username', '用户名')->sortable()->fixed('left')->mobile(),
                Column::text('mobile', '手机号'),                   // FieldPolicy: hidden → removed, masked → masked: true
                Column::tag('status', '状态')->enum(AdminStatus::class)->mobile(),
                Column::datetime('created_at', '创建时间')->format('yyyy-MM-dd HH:mm'),
            ])
            ->toolbar([
                Action::openForm('新增', 'admin.system.admin-form')->can('system:admin:create')->primary(),
            ])
            ->rowActions([
                Action::openForm('编辑', 'admin.system.admin-form', ['id' => '{id}'])->can('system:admin:edit'),
                Action::request('删除', 'DELETE', '/admin/api/admins/{id}')
                    ->confirm('确定删除 {username}？')->can('system:admin:delete')->danger(),
            ]);
    }
}
```

The other builders follow the same pattern:

- `Table` options: `->tree(children: 'children', column: null, expanded: true)` makes a tree table
  (the API returns the whole tree; rows are indented in `column`, default the first column; no
  pager), `->paginate(false)` shows a full flat list without a pager, and
  `->notice('来源：{files} · 版本 {hash}', '/api/sources', 'info')` shows a notice above the table
  (placeholders are filled from the object that `api` returns; without `api` the text is static).
- `Form::make()`: `->load()`, `->submit(method, api, mode: create|update)`, `->columns(2)`,
  `->sections([Section::make('基本信息', [...])])` or `->fields([...])`, `->actions()`. The default
  actions are submit and close.
- `Dashboard::make()`: `->blocks([...])`.
- `Detail::make()`: `->load()`, `->header('{name}', tags, actions)`, then either `->tabs([Tab::make(...)])`
  or `->blocks()`.
- `Custom::make()`: `->component('role-permission-editor')->config([...])`.

Every node can use `->can(...codes)` (any-of) and `->pcOnly()` / `->mobileOnly()` / `->platforms()`.
On a builder, `->platforms()` sets `meta.platforms` for the page.

Row / record conditions (display hints only; the API must still enforce the rule):

- `Field::visibleWhen(field, op, value)`: show the field only when another form value matches.
- `Action::visibleWhen(field, op, value)` / `Action::hiddenWhen(field, op, value)`: show or hide a
  row action (or a form / detail action) depending on the current row or loaded record, for example
  `->hiddenWhen('is_super', 'in', [true, 1])` keeps 删除 off the super admin row.
- `op` is `eq` (scalar), `in` (list) or `notEmpty`. There are no expressions or scripts.

Components (RENDER.md §7):

| kind | names |
|---|---|
| Field | text textarea password number money select radio checkbox switch date datetime dateRange time treeSelect cascader upload image richText json (read-only) |
| Column | text tag badge datetime money image link copyable progress bool |
| Block | stat chart rank shortcuts descriptions table (embedded page) timeline markdown alert card grid collapse |
| Action | openForm openDetail navigate request batchRequest export import refresh submit close; `->then(refresh\|close\|navigate\|toast)` |

Texts, APIs and params may contain `{field}` placeholders (current row, record or route parameter).
Only field paths are allowed, never expressions. APIs must be relative paths (`/…`). Definition
errors throw `InvalidDefinition` with the component and field named.

## Charts (renderer-neutral)

```php
Chart::line('近30天开通')
    ->api('/admin/api/stats/opens')
    ->x('date', type: 'time', format: 'MM-dd')
    ->series('count', '开通数', color: '#2563eb', area: true, smooth: true)
    ->series('closed', '停用数', color: '#ef4444', dashed: true, axis: 'right')
    ->yAxis('left', min: 0, format: '{value}')
    ->yAxis('right', min: 0, format: '{value}%')
    ->legend('top')->tooltip(shared: true, format: '{series}: {value|thousands}')
    ->markLine(100, '目标', color: '#16a34a')->markArea('2026-09-01', '2026-09-07', '活动期')
    ->palette(['#2563eb', '#f59e0b', '#10b981'])
    ->labels(true, '{value}')->emptyText('暂无数据')
    ->renderer('fl_chart', ['lineWidth' => 2])->renderer('ucharts', ['padding' => [15, 15, 0, 5]])
    ->height(320)->span(16)->refresh(60)->can('stats:view');

Chart::donut('套餐占比')->api('/admin/api/stats/plans')->x('name')->series('count')->labels(true, '{name} {percent|percent:1}');
Chart::combo('收入/订单')->api('/s')->x('month')->series('income', type: 'bar')->series('orders', type: 'line', axis: 'right');
Chart::custom('heatmap', ['api' => '/admin/api/stats/heat'], '活跃热力');   // client-registered chart component
```

**Types:** `line` `bar` `stackedBar` `area` `pie` `donut` `radar` `scatter` `gauge` `combo` `custom`.

**Rules checked when the chart is rendered:**
- pie, donut and gauge take exactly one series.
- Each combo series needs a `type`.
- Right-axis series and marks are allowed only on cartesian charts.
- `api`, `x` and at least one series are required (`x` is optional for gauge).

**Formats** are placeholder templates only:
- Placeholders: `{value}` `{series}` `{x}` `{name}` `{percent}`.
- Filters: `|thousands` `|percent:N` `|fixed:N` `|money:CNY` `|date:MM-dd`.

**Colors** are `#rgb`, `#rrggbb` or `#rrggbbaa`, or theme tokens (`primary`, `success`, …).

**`renderer()` passthrough** only accepts whitelisted keys per renderer (`Chart::RENDERER_OPTIONS`)
with scalar or list-of-scalar values. You can extend the whitelist at boot with
`Chart::allowRendererOption('ucharts', 'xAxisFontSize')`.

## Serving pages (host side)

```php
$registry = new PageRegistry(fn (string $class) => $container->get($class));   // factory optional
$registry->discoverApps(BASE_PATH . '/apps');                                     // apps/<App>/Pages → App\<App>\Pages\…

// GET /admin/api/pages/{page}
$client = ClientInfo::fromHeaders($request->headers());     // X-Client-Platform, X-Client-Env, X-Render-Schema, X-Render-Components
$ctx    = new PageContext($client, params: $request->query(), user: $user);
$def    = $registry->get($code);                             // PageNotFound → 404
$key    = CacheKey::for($def, $ctx, $resolver);              // render:s1:<code>:<pc|mobile.env>:<permission fingerprint>:<page version>
$desc   = $cache->get($key) ?? $registry->render($code, $ctx, $resolver, $fieldPolicy, expected: $menuNode['page_type']);
$etag   = Etag::of($desc);
if (Etag::matches($request->header('If-None-Match'), $etag)) { /* 304 */ }
// 200 {code: 0, message: 'ok', data: $desc} + ETag header
```

`render()` runs these steps in order:

1. **Schema check:** the client's schema must be ≥ `Schema::VERSION`, otherwise `SchemaNotSupported`
   (ask the user to upgrade the client).
2. **Page lookup:** an unknown code throws `PageNotFound`.
3. **Page permission:** checked with `PermissionResolver::allows` (any-of). Denied gives
   `PageForbidden` (HTTP 403).
4. **Build and type check:** the page is built, and the builder type must match both the class type and
   the menu `page_type`. Otherwise `PageTypeMismatch`.
5. **Platform check:** a page not available on the client's platform throws `PlatformNotSupported`.
6. **Per-node rules**, applied to every node:
   - `->can()` nodes without permission are **removed**. Containers left empty are removed too.
   - `FieldPolicy`: `Hidden` removes the field or column. `Readonly` gives `readonly: true`. `Masked`
     gives `masked: true` (form fields are also read-only).
   - Mobile trimming:
     - Tables keep only `->mobile()` columns. If none is marked, the first 3 are kept.
     - No selection, no fixed columns or widths.
     - Forms are single column.
     - Blocks lose `span` (they stack), and `descriptions` use 1 column.
     - Components in `ComponentCatalog`'s unsupported list are removed. The default list is mobile
       `field:richText`, `action:batchRequest` and `action:import`.

The result has this shape:

```json
{ "schema": 1, "page": "admin.system.admins", "type": "table", "title": "管理员", "version": "1f0c9a7d2b3e",
  "breadcrumb": ["系统管理", "管理员"], "body": { "api": "/admin/api/admins", "rowKey": "id", "columns": [ ... ] },
  "meta": { "platforms": ["pc", "mobile"], "platform": "mobile", "env": "mp-weixin" } }
```

`version` is a 12-hex hash of the page version and the rendered content. It changes with the user's
permissions and the platform, and is used as the ETag.

Other registry tools:
- `assertType(code, page_type)`: for `admin:menus`. The error names the class and file.
- `inspect(code)`: every `->can()` code and every `resource.field` the page uses. Use it to check
  menu.json button codes and `resource_fields`.

Extension interfaces:

```php
interface PermissionResolver { public function allows(string $code, PageContext $ctx): bool; public function fingerprint(PageContext $ctx): string; }
interface FieldPolicy       { public function access(string $resource, string $field, PageContext $ctx): FieldAccess; }  // Hidden | Masked | Readonly | Editable
```

## Languages (i18n)

loongs/render has no translator of its own; it defines the one-method contract
`Contract\Translator::translate(string $text, string $locale): ?string` (null = keep the source text).
Use [loongs/language](https://github.com/RenLoong/loongs-language) (packs, Accept-Language
negotiation, fallback chains, per-coroutine locale) through its adapter, or any closure:

```php
use Loongs\Language\{Catalog, Translator};
use Loongs\Language\Integration\Render\RenderTranslator;

$t = new Translator(Catalog::fromDirectories($appsDir . '/Admin/lang'), fallback: 'zh-CN');
$registry = new PageRegistry($factory, translator: new RenderTranslator($t));
// or: new PageRegistry(translator: new I18n\CallbackTranslator(['en-US' => ['管理员' => 'Administrators']]))

$client = ClientInfo::fromHeaders($headers)->withLocale($t->negotiate($headers['accept-language'] ?? null));
$desc = $registry->render($code, new PageContext($client), $permissions);
// response: ETag from $desc, plus "Vary: Accept-Language"
```

- Page builders keep writing source-language texts (`Table::make('管理员')`, `Field::text('name', '名称')`);
  the registry translates the built description: title, breadcrumb and, anywhere in the body, `title`,
  `label`, `message`, `placeholder`, `help`, `confirm`, `emptyText`, `format`, `okText`, `cancelText`,
  `description`, `text`, `unit`, `prefix`, `suffix`, chart series `name`, markdown / alert `content`
  (`I18n\DescriptionTranslator::TEXT_KEYS`). Field `name`, `field`, `api`, values, icons and colors are
  never touched. Templates are translated whole and keep their placeholders (`确定删除 {username}？` →
  `Delete {username}?`).
- `ClientInfo::$locale` is part of `cacheKey()` / `Http\CacheKey`, and `meta.locale` is part of the
  description `version`, so ETags differ per language.
- `PageRegistry::inspect($code)['texts']` lists every translatable string of a page (PC + mobile) —
  feed it to `Translator::missingKeys($texts, 'en-US')` in a CI / console check.

## Theme colors (light / dark)

Colors in descriptions (option / tag colors, chart `series.color`, `palette`, mark lines / areas) are:

- semantic tokens resolved by the client for its current theme and accent: `primary`, `secondary`,
  `success`, `warning`, `danger`, `info`, `muted`, `default`, and the categorical palette `chart1` …
  `chart8` (`Support\Color::palette()`);
- fixed hex colors `#rgb` / `#rrggbb` / `#rrggbbaa` (same in both themes);
- light/dark pairs `"light|dark"` (`Color::pair('#16a34a', '#4ade80')`, tokens allowed on either side).

Prefer tokens; a chart without `palette` uses the client's themed default palette.

## JSON Schema for frontend tests

`Schema::jsonSchema()` / `bin/render-schema` / `schema/page.v1.json` give the JSON Schema
(draft 2020-12) of description schema 1. Flutter and uni-app test suites can validate their fixtures
against it. In PHP, use `(new SchemaValidator())->validate($desc)`, which returns a list of
`"/json/pointer: message"` errors.

## Tests

```bash
php tests/render_test.php          # or: composer test
```

## Development (path repo)

```json
"repositories": [{ "type": "path", "url": "../../composer/render", "options": { "symlink": true } }],
"require": { "loongs/render": "dev-main" }
```

## Notes

- There is no loongs/framework dependency. The HTTP route, cache store wiring and `admin:menus` checks
  belong to the host (admin, R1). The package provides `Http\Etag`, `Http\CacheKey`,
  `PageRegistry::assertType()` and `inspect()` for that work.
- `x` in a chart is an object `{field, type, format?}`, and `series` is a list of objects. These replace
  the short `"x": "date", "y": ["count"]` form in the RENDER.md §6.1 example.
- Header tags and enum columns carry expanded `options` (`{value,label,color}`) instead of an enum name.
- `ComponentCatalog` adds `card`, `grid` and `collapse` as blocks, and `submit` and `close` as actions,
  to implement the §7 layouts and form buttons.
