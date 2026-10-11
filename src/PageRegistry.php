<?php

declare(strict_types=1);

namespace Loongs\Render;

use Closure;
use FilesystemIterator;
use Loongs\Render\Builder\Builder;
use Loongs\Render\Contract\FieldPolicy;
use Loongs\Render\Contract\PermissionResolver;
use Loongs\Render\Contract\Translator;
use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\Exception\PageForbidden;
use Loongs\Render\Exception\PageNotFound;
use Loongs\Render\Exception\PageTypeMismatch;
use Loongs\Render\Exception\PlatformNotSupported;
use Loongs\Render\Exception\SchemaNotSupported;
use Loongs\Render\I18n\DescriptionTranslator;
use Loongs\Render\Policy\AllowAll;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Page code → page class registry (RENDER.md §4.2–§4.4).
 *
 *   $registry = new PageRegistry();
 *   $registry->discoverApps('/path/apps');                  // apps/<App>/Pages/*.php, App\<App>\Pages\…
 *   $desc = $registry->render('uuims.system.users', $ctx, $resolver, $fieldPolicy, PageType::Table);
 *
 * render() applies, in order: schema version → page exists → page permission (PageForbidden = 403)
 * → build + type check → platform → ->can() / field policy / platform trimming of every node.
 */
final class PageRegistry
{
    /** @var array<string, PageDefinition> */
    private array $pages = [];
    /** @var Closure(class-string<Page>): Page */
    private Closure $factory;

    /** @param (Closure(class-string<Page>): Page)|null $factory how page classes are instantiated (DI container) */
    public function __construct(
        ?Closure $factory = null,
        public readonly ComponentCatalog $catalog = new ComponentCatalog(),
        public readonly ?Translator $translator = null,
    ) {
        $this->factory = $factory ?? static fn (string $class): Page => new $class();
    }

    /** @param class-string<Page> $class */
    public function register(string $class): PageDefinition
    {
        if (!class_exists($class)) {
            throw new InvalidDefinition("page class {$class} not found");
        }
        $ref = new ReflectionClass($class);
        if (!$ref->isSubclassOf(Page::class) || $ref->isAbstract()) {
            throw new InvalidDefinition("{$class} must be a concrete subclass of " . Page::class);
        }
        $attrs = $ref->getAttributes(AsPage::class);
        if ($attrs === []) {
            throw new InvalidDefinition("{$class} has no #[AsPage] attribute");
        }
        /** @var AsPage $as */
        $as = $attrs[0]->newInstance();
        $code = PageCode::assert($as->code);
        if (isset($this->pages[$code]) && $this->pages[$code]->class !== $class) {
            throw new InvalidDefinition("duplicate page code \"{$code}\": {$this->pages[$code]->class} and {$class}");
        }
        foreach ($as->permissions as $p) {
            (is_string($p) && preg_match('/^[A-Za-z0-9_.:*-]{1,128}$/', $p) === 1) || throw new InvalidDefinition("{$class}: invalid permission code");
        }
        $file = $ref->getFileName() ?: null;
        $version = $as->version ?? ($file !== null ? substr(hash_file('sha256', $file), 0, 8) : '0');

        return $this->pages[$code] = new PageDefinition($code, $class, $class::type(), $as->permissions, $version, $file);
    }

    /**
     * Registers every concrete #[AsPage] class below $dir. Class names follow PSR-4 from $namespace;
     * files are required when the class is not autoloadable.
     *
     * @return list<PageDefinition>
     */
    public function discover(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $namespace = trim($namespace, '\\');
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        $found = [];
        foreach ($files as $path) {
            $rel = substr($path, strlen(rtrim($dir, '/\\')) + 1, -4);
            $class = $namespace . '\\' . str_replace(['/', '\\'], '\\', $rel);
            if (!class_exists($class)) {
                require_once $path;
            }
            if (!class_exists($class, false)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || !$ref->isSubclassOf(Page::class) || $ref->getAttributes(AsPage::class) === []) {
                continue;
            }
            $found[] = $this->register($class);
        }

        return $found;
    }

    /**
     * apps/<App>/<sub>/ → <baseNamespace><App>\<sub>\ (RENDER.md §4.2: every app's Pages/).
     *
     * @return list<PageDefinition>
     */
    public function discoverApps(string $appsDir, string $baseNamespace = 'App\\', string $sub = 'Pages'): array
    {
        $found = [];
        foreach (glob(rtrim($appsDir, '/\\') . '/*', GLOB_ONLYDIR) ?: [] as $app) {
            $found = [...$found, ...$this->discover("{$app}/{$sub}", rtrim($baseNamespace, '\\') . '\\' . basename($app) . '\\' . $sub)];
        }

        return $found;
    }

    public function has(string $code): bool
    {
        return isset($this->pages[$code]);
    }

    public function get(string $code): PageDefinition
    {
        return $this->pages[$code] ?? throw new PageNotFound("page \"{$code}\" is not registered");
    }

    /** @return array<string, PageDefinition> sorted by code */
    public function definitions(): array
    {
        $p = $this->pages;
        ksort($p);

        return $p;
    }

    /**
     * For menu checks (uuims:menus): the registered class type and the builder it actually returns must
     * both equal the menu page_type.
     */
    public function assertType(string $code, PageType|string $expected, ?PageContext $ctx = null): void
    {
        $expected = $expected instanceof PageType ? $expected : (PageType::tryFrom($expected) ?? throw new InvalidDefinition("unknown page_type \"{$expected}\""));
        $def = $this->get($code);
        $builder = $this->build($def, ($ctx ?? new PageContext())->withPage($code));
        if ($def->type !== $expected || $builder->type() !== $expected) {
            throw new PageTypeMismatch(sprintf('page "%s" (%s, %s) builds %s but page_type is %s', $code, $def->class, $def->file ?? '?', $builder->type()->value, $expected->value));
        }
    }

    /**
     * Builds the page description for one user + client.
     *
     * @return array<string, mixed> {schema, page, type, title, version, breadcrumb, body, meta}
     * @throws PageNotFound|PageForbidden|PageTypeMismatch|PlatformNotSupported|SchemaNotSupported|InvalidDefinition
     */
    public function render(
        string $code,
        PageContext $ctx,
        PermissionResolver $permissions = new AllowAll(),
        ?FieldPolicy $fieldPolicy = null,
        PageType|string|null $expected = null,
    ): array {
        if ($ctx->client->schema < Schema::VERSION) {
            throw new SchemaNotSupported(sprintf('client understands description schema %d, server sends %d — please upgrade the client', $ctx->client->schema, Schema::VERSION));
        }
        $def = $this->get($code);
        $ctx = $ctx->withPage($code);
        if ($def->permissions !== []) {
            $ok = false;
            foreach ($def->permissions as $p) {
                $ok = $ok || $permissions->allows($p, $ctx);
            }
            $ok || throw new PageForbidden("no permission for page \"{$code}\"");
        }
        $builder = $this->build($def, $ctx);
        $expected = is_string($expected) ? (PageType::tryFrom($expected) ?? throw new InvalidDefinition("unknown page_type \"{$expected}\"")) : $expected;
        if ($builder->type() !== $def->type || ($expected !== null && $builder->type() !== $expected)) {
            throw new PageTypeMismatch(sprintf('page "%s" builds %s, expected %s', $code, $builder->type()->value, ($expected ?? $def->type)->value));
        }
        $platforms = $builder->supportedPlatforms();
        if (!in_array($ctx->client->platform, $platforms, true)) {
            throw new PlatformNotSupported("page \"{$code}\" is not available on {$ctx->client->platform->value}");
        }
        $rc = new RenderContext($ctx, $permissions, $fieldPolicy, $this->catalog);
        $body = $builder->toArray($rc);
        $locale = $ctx->client->locale;

        $desc = [
            'schema' => Schema::VERSION,
            'page' => $code,
            'type' => $builder->type()->value,
            'title' => $builder->title,
            'version' => '',
            'breadcrumb' => $builder->breadcrumb,
            'body' => $body,
            'meta' => array_filter([
                'platforms' => array_map(static fn (Platform $p): string => $p->value, $platforms),
                'platform' => $ctx->client->platform->value,
                'env' => $ctx->client->env?->value,
                'locale' => $locale,
            ], static fn ($v): bool => $v !== null),
        ];
        if ($this->translator !== null && $locale !== null) {
            $tr = new DescriptionTranslator();
            $desc['title'] = $this->translator->translate($desc['title'], $locale) ?? $desc['title'];
            $desc['breadcrumb'] = array_map(fn (string $s): string => $this->translator->translate($s, $locale) ?? $s, $desc['breadcrumb']);
            $desc['body'] = $tr->translate($desc['body'], $this->translator, $locale);
        }
        // after translation: the version (→ ETag) differs per language
        $desc['version'] = substr(hash('sha256', $def->version . json_encode($desc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);

        return $desc;
    }

    /**
     * What a page may ask for: every permission code used by ->can() and every "resource.field" seen by
     * the field policy (built with all permissions granted, for PC and mobile). Useful to check menu.json
     * button codes and resource_fields.
     *
     * "texts" lists every translatable string (title, breadcrumb and DescriptionTranslator texts) for
     * missing-translation checks.
     *
     * @return array{type: string, permissions: list<string>, codes: list<string>, fields: list<string>, texts: list<string>}
     */
    public function inspect(string $code): array
    {
        $def = $this->get($code);
        $codes = $fields = $texts = [];
        $tr = new DescriptionTranslator();
        foreach ([ClientInfo::pc(), ClientInfo::mobile()] as $client) {
            $ctx = (new PageContext($client))->withPage($code);
            $builder = $this->build($def, $ctx);
            if (!in_array($client->platform, $builder->supportedPlatforms(), true)) {
                continue;
            }
            $rc = new RenderContext($ctx, new AllowAll(), null, $this->catalog);
            $body = $builder->toArray($rc);
            $codes += $rc->codes;
            $fields += $rc->fields;
            foreach ([$builder->title, ...$builder->breadcrumb, ...$tr->texts($body)] as $s) {
                if ($s !== '') {
                    $texts[$s] = true;
                }
            }
        }
        ksort($codes);
        ksort($fields);

        return ['type' => $def->type->value, 'permissions' => $def->permissions, 'codes' => array_keys($codes), 'fields' => array_keys($fields),
            'texts' => array_map('strval', array_keys($texts))];
    }

    private function build(PageDefinition $def, PageContext $ctx): Builder
    {
        $page = ($this->factory)($def->class);
        if (!$page instanceof Page) {
            throw new InvalidDefinition("factory returned " . get_debug_type($page) . " for {$def->class}");
        }

        return $page->build($ctx);
    }
}
