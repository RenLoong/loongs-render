<?php

declare(strict_types=1);

namespace Fixture;

use Loongs\Render\Contract\OptionEnum;

enum AdminStatus: int implements OptionEnum
{
    case Active = 1;
    case Disabled = 0;

    public function label(): string
    {
        return match ($this) {
            self::Active => '正常',
            self::Disabled => '禁用',
        };
    }

    public function color(): ?string
    {
        return match ($this) {
            self::Active => 'success',
            self::Disabled => '#ef4444',
        };
    }
}
