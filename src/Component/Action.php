<?php

declare(strict_types=1);

namespace Loongs\Render\Component;

use Loongs\Render\Exception\InvalidDefinition;
use Loongs\Render\PageCode;
use Loongs\Render\RenderContext;
use Loongs\Render\Support\Condition;
use Loongs\Render\Support\Placeholder;

/**
 * Buttons / operations (RENDER.md §5.3). Labels, confirm texts, params and APIs may use {field}
 * placeholders of the current row / record / route.
 *
 *   Action::openForm('编辑', 'uuims.system.uuims-form', ['id' => '{id}'])->can('system:uuims:edit')
 *   Action::request('删除', 'DELETE', '/uuims/api/users/{id}')->confirm('确定删除 {username}？')->danger()
 *       ->hiddenWhen('is_super', 'in', [true, 1])
 *
 * visibleWhen / hiddenWhen are evaluated by the client against the current row (rowActions) or the
 * loaded record (form / detail actions); they are display hints only, the API must still enforce the rule.
 */
final class Action extends Node
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    public const MODES = ['dialog', 'drawer', 'page'];
    public const THEN = ['refresh', 'close', 'navigate', 'toast'];

    /** @var array<string, mixed> */
    private array $args = [];
    private ?string $confirm = null;
    private bool $primary = false;
    private bool $danger = false;
    private ?string $icon = null;
    private ?string $then = null;
    private ?string $thenPath = null;
    private ?string $message = null;
    private ?array $visibleWhen = null;
    private ?array $hiddenWhen = null;

    private function __construct(
        public private(set) string $action,
        public private(set) string $label,
    ) {
        Placeholder::assert($label, "action {$label}");
    }

    /** @param array<string, scalar|null> $params */
    public static function openForm(string $label, string $page, array $params = [], string $mode = 'dialog'): self
    {
        $a = new self('openForm', $label);
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidDefinition("action {$label}: mode must be dialog | drawer | page");
        }
        $a->args = ['page' => PageCode::assert($page), 'params' => $params !== [] ? self::params($params, "action {$label}") : null, 'mode' => $mode];

        return $a;
    }

    /** @param array<string, scalar|null> $params */
    public static function openDetail(string $label, string $page, array $params = [], string $mode = 'page'): self
    {
        $a = self::openForm($label, $page, $params, $mode);
        $a->action = 'openDetail';

        return $a;
    }

    /** @param array<string, scalar|null> $query */
    public static function navigate(string $label, string $path, array $query = []): self
    {
        $a = new self('navigate', $label);
        $a->args = ['path' => self::path($path, "action {$label} path"), 'query' => $query !== [] ? self::params($query, "action {$label}") : null];

        return $a;
    }

    /** @param array<string, mixed>|null $body JSON body; string values may be {field} templates */
    public static function request(string $label, string $method, string $api, ?array $body = null): self
    {
        $a = new self('request', $label);
        $a->args = ['method' => self::method($method, $label), 'api' => self::path($api, "action {$label}"), 'body' => $body !== null && $body !== [] ? self::body($body, $label) : null];

        return $a->then('refresh');
    }

    /** Table multi-select; the client posts {ids: [...]} (rowKey values). */
    public static function batchRequest(string $label, string $method, string $api): self
    {
        $a = new self('batchRequest', $label);
        $a->args = ['method' => self::method($method, $label), 'api' => self::path($api, "action {$label}")];

        return $a->then('refresh');
    }

    /** P2 (file / Excel service) */
    public static function export(string $label, string $api): self
    {
        $a = new self('export', $label);
        $a->args = ['api' => self::path($api, "action {$label}")];

        return $a;
    }

    /** P2 (file / Excel service) */
    public static function import(string $label, string $api): self
    {
        $a = new self('import', $label);
        $a->args = ['api' => self::path($api, "action {$label}")];

        return $a->then('refresh');
    }

    /** @param string|null $target block id; null = whole page */
    public static function refresh(string $label = '刷新', ?string $target = null): self
    {
        $a = new self('refresh', $label);
        if ($target !== null) {
            self::name($target, "action {$label} target");
        }
        $a->args = ['target' => $target];

        return $a;
    }

    /** Form: submit to the form's submit API. */
    public static function submit(string $label = '保存'): self
    {
        return (new self('submit', $label))->primary()->then('close');
    }

    /** Form / dialog: close without saving. */
    public static function close(string $label = '取消'): self
    {
        return new self('close', $label);
    }

    public function confirm(string $text): self { $this->confirm = Placeholder::assert($text, "action {$this->label} confirm"); return $this; }
    public function primary(bool $primary = true): self { $this->primary = $primary; return $this; }
    public function danger(bool $danger = true): self { $this->danger = $danger; return $this; }
    public function icon(string $icon): self
    {
        $this->icon = preg_match('/^[a-z0-9-]{1,48}$/', $icon) === 1 ? $icon : throw new InvalidDefinition("action {$this->label}: icon must be [a-z0-9-]");

        return $this;
    }

    /** What happens after success: refresh | close | navigate ($path) | toast ($message). */
    public function then(string $then, ?string $pathOrMessage = null): self
    {
        if (!in_array($then, self::THEN, true)) {
            throw new InvalidDefinition("action {$this->label}: then must be " . implode(' | ', self::THEN));
        }
        $this->then = $then;
        $this->thenPath = $then === 'navigate' ? self::path((string) $pathOrMessage, "action {$this->label} then") : null;
        if ($then === 'toast' && $pathOrMessage !== null) {
            $this->message = $pathOrMessage;
        }

        return $this;
    }

    /** Show only when the current row / record matches: op eq | in | notEmpty (no scripts). */
    public function visibleWhen(string $field, string $op, mixed $value = null): self
    {
        self::name($field, "action {$this->label} visibleWhen");
        $this->visibleWhen = Condition::make("action {$this->label}", 'visibleWhen', $field, $op, $value);

        return $this;
    }

    /** Hide when the current row / record matches: op eq | in | notEmpty (no scripts). */
    public function hiddenWhen(string $field, string $op, mixed $value = null): self
    {
        self::name($field, "action {$this->label} hiddenWhen");
        $this->hiddenWhen = Condition::make("action {$this->label}", 'hiddenWhen', $field, $op, $value);

        return $this;
    }

    /** Success toast text (shown together with then). */
    public function message(string $text): self { $this->message = Placeholder::assert($text, "action {$this->label} message"); return $this; }

    public function toArray(RenderContext $rc): ?array
    {
        if (!$this->visible($rc) || !$rc->supports('action', $this->action)) {
            return null;
        }

        return self::compact([
            'label' => $this->label,
            'action' => $this->action,
            ...self::compact($this->args),
            'confirm' => $this->confirm,
            'primary' => $this->primary ?: null,
            'danger' => $this->danger ?: null,
            'icon' => $this->icon,
            'then' => $this->then,
            'thenPath' => $this->thenPath,
            'message' => $this->message,
            'visibleWhen' => $this->visibleWhen,
            'hiddenWhen' => $this->hiddenWhen,
        ]);
    }

    private static function method(string $method, string $label): string
    {
        $m = strtoupper($method);

        return in_array($m, self::METHODS, true) ? $m : throw new InvalidDefinition("action {$label}: method must be " . implode(' | ', self::METHODS));
    }

    private static function body(array $body, string $label): array
    {
        array_walk_recursive($body, static function ($v, $k) use ($label): void {
            if (!is_scalar($v) && $v !== null) {
                throw new InvalidDefinition("action {$label}: body.{$k} must be JSON");
            }
            if (is_string($v)) {
                Placeholder::assert($v, "action {$label} body.{$k}");
            }
        });

        return $body;
    }
}
