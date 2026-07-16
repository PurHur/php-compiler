<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::toggleAttribute() nested helper for user-script AOT (#19507).
 *
 * php-src: ext/dom/element.c — dom_element_toggle_attribute
 *
 * Nested helper TUs mishandle native bool returns from VmDom; encode via strlen.
 */
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
