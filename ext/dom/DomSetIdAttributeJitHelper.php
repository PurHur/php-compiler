<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::setIdAttribute{,NS,Node}() — user-script AOT (#29257, #29284).
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

    /** Empty $namespace → null URI (peer getAttributeNodeNS ABI). */
    public static function setIdNsTrueArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        $ns = '' === $namespace ? null : $namespace;
        VmDom::setIdAttributeNSWithoutIdMapSync($element, $ns, $localName, true);
    }

    public static function setIdNsFalseArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        $ns = '' === $namespace ? null : $namespace;
        VmDom::setIdAttributeNSWithoutIdMapSync($element, $ns, $localName, false);
    }

    public static function setIdNodeTrueArgv(ObjectEntry $element, ObjectEntry $attr): void
    {
        VmDom::setIdAttributeNodeWithoutIdMapSync($element, $attr, true);
    }

    public static function setIdNodeFalseArgv(ObjectEntry $element, ObjectEntry $attr): void
    {
        VmDom::setIdAttributeNodeWithoutIdMapSync($element, $attr, false);
    }
}
