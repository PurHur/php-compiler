<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::setIdAttribute{,NS,Node}() — user-script AOT (#29257, #29284).
 * Sync PROP_ELEMENT_ID_MAP so multi-id getElementById hits the hashtable (#34696).
 */
final class DomSetIdAttributeJitHelper
{
    public static function setIdTrueArgv(ObjectEntry $element, string $name): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        VmDom::setIdAttribute($element, $name, true);
    }

    public static function setIdFalseArgv(ObjectEntry $element, string $name): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        VmDom::setIdAttribute($element, $name, false);
    }

    /** Empty $namespace → null URI (peer getAttributeNodeNS ABI). */
    public static function setIdNsTrueArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        $ns = '' === $namespace ? null : $namespace;
        VmDom::setIdAttributeNS($element, $ns, $localName, true);
    }

    public static function setIdNsFalseArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        $ns = '' === $namespace ? null : $namespace;
        VmDom::setIdAttributeNS($element, $ns, $localName, false);
    }

    public static function setIdNodeTrueArgv(ObjectEntry $element, ObjectEntry $attr): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        VmDom::setIdAttributeNode($element, $attr, true);
    }

    public static function setIdNodeFalseArgv(ObjectEntry $element, ObjectEntry $attr): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        VmDom::setIdAttributeNode($element, $attr, false);
    }
}
