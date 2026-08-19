<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::toggleAttribute() — user-script AOT (#19507).
 *
 * Returns int 0/1 for int1 bridge ABIs. Delegates to VmDom::toggleAttribute so
 * DomRegistry attribute state drives omit/force semantics (php-src ext/dom/element.c).
 */
final class DomToggleAttributeJitHelper
{
    public static function toggleOmitArgv(Context $ctx, ObjectEntry $element, string $name): int
    {
        return VmDom::toggleAttribute($ctx, $element, $name, null) ? 1 : 0;
    }

    public static function toggleForceTrueArgv(Context $ctx, ObjectEntry $element, string $name): int
    {
        return VmDom::toggleAttribute($ctx, $element, $name, true) ? 1 : 0;
    }

    public static function toggleForceFalseArgv(Context $ctx, ObjectEntry $element, string $name): int
    {
        return VmDom::toggleAttribute($ctx, $element, $name, false) ? 1 : 0;
    }
}
