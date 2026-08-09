<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::setIdAttribute() — user-script AOT (#29257).
 * Void NestedJIT ABI; DomRegistry only (no PROP_ELEMENT_ID_MAP sync).
 */
final class DomSetIdAttributeJitHelper
{
    public static function setIdTrueArgv(ObjectEntry $element, string $name): void
    {
        VmDom::setIdAttributeWithoutIdMapSync($element, $name, true);
    }

    public static function setIdFalseArgv(ObjectEntry $element, string $name): void
    {
        VmDom::setIdAttributeWithoutIdMapSync($element, $name, false);
    }
}
