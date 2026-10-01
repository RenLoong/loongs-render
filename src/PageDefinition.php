<?php

declare(strict_types=1);

namespace Loongs\Render;

/** A registered page: code, class, type, page permission (any-of) and version. */
final readonly class PageDefinition
{
    /**
     * @param class-string<Page> $class
     * @param list<string>       $permissions
     */
    public function __construct(
        public string $code,
        public string $class,
        public PageType $type,
        public array $permissions,
        public string $version,
        public ?string $file = null,
    ) {
    }

    /** @return array{code: string, class: string, type: string, permissions: list<string>, version: string, file: ?string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'class' => $this->class, 'type' => $this->type->value, 'permissions' => $this->permissions,
            'version' => $this->version, 'file' => $this->file];
    }
}
