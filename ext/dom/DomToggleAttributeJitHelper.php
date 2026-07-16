<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
/** DOMElement::toggleAttribute() — user-script AOT (#19507). */
final class DomToggleAttributeJitHelper
{
    /** @param int $forceFlag -1 omit/null, 0 false, 1 true */
    public static function toggleAttributeArgv(
        Context $ctx,
        ObjectEntry $element,
        string $name,
        int $forceFlag
    ): int {
        $canonical = DomRegistry::entry($element->id) ?? $element;
        $force = -1 === $forceFlag ? null : (0 !== $forceFlag);
        return \strlen(VmDom::toggleAttribute($ctx, $canonical, $name, $force) ? '1' : '');
    }
}
