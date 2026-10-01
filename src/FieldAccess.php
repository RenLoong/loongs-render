<?php

declare(strict_types=1);

namespace Loongs\Render;

/**
 * Result of FieldPolicy for one field / column (RENDER.md §4.4):
 *   Hidden   → removed from the description (name and label never sent)
 *   Masked   → column / item `masked: true`, form field readonly + masked (real masking stays in the data API)
 *   Readonly → form field `readonly: true`
 *   Editable → unchanged
 */
enum FieldAccess: string
{
    case Hidden = 'hidden';
    case Masked = 'masked';
    case Readonly = 'readonly';
    case Editable = 'editable';
}
