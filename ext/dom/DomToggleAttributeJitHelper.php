<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** DEBUG probe helper — returns encoded diagnostics as int64 (#19507). */
final class DomToggleAttributeJitHelper
{
    public static function toggleAttributeArgv(
        Context $ctx,
        ObjectEntry $element,
        string $name,
        int $forceFlag
    ): int {
        // 1000+forceFlag shows marshalling; then real toggle result * 1 or 0.
        $canonical = DomRegistry::entry($element->id) ?? $element;
        $force = -1 === $forceFlag ? null : (0 !== $forceFlag);
        $ok = VmDom::toggleAttribute($ctx, $canonical, $name, $force) ? 1 : 0;

        // Encode: forceFlag in low bits via 1000+forceFlag, plus 10000 if ok.
        return (1000 + $forceFlag) + ($ok * 10000) + \strlen($name);
    }
}
