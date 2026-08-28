<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
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
        $value = DomUserScriptAttributeCacheLlvm::literalValue('', $name) ?? '';
        VmDom::seedThinAotElementAttribute($element, $name, $value);
        VmDom::setIdAttribute($element, $name, true);
    }

    public static function setIdFalseArgv(ObjectEntry $element, string $name): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        $value = DomUserScriptAttributeCacheLlvm::literalValue('', $name) ?? '';
        VmDom::seedThinAotElementAttribute($element, $name, $value);
        VmDom::setIdAttribute($element, $name, false);
    }

    /** Empty $namespace → null URI (peer getAttributeNodeNS ABI). */
    public static function setIdNsTrueArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        $ns = '' === $namespace ? null : $namespace;
        $value = DomUserScriptAttributeCacheLlvm::literalValue($namespace, $localName) ?? '';
        VmDom::seedThinAotNsElementAttribute($element, $ns, $localName, $value);
        VmDom::setIdAttributeNS($element, $ns, $localName, true);
    }

    public static function setIdNsFalseArgv(ObjectEntry $element, string $namespace, string $localName): void
    {
        VmDom::syncDomRegistryParentChainFromProperties($element);
        $ns = '' === $namespace ? null : $namespace;
        $value = DomUserScriptAttributeCacheLlvm::literalValue($namespace, $localName) ?? '';
        VmDom::seedThinAotNsElementAttribute($element, $ns, $localName, $value);
        VmDom::setIdAttributeNS($element, $ns, $localName, false);
    }

    public static function setIdNodeTrueArgv(Context $ctx, ObjectEntry $attr): void
    {
        VmDom::setIdAttributeNodeOnAttrOwner($attr, true);
    }

    public static function setIdNodeFalseArgv(Context $ctx, ObjectEntry $attr): void
    {
        VmDom::setIdAttributeNodeOnAttrOwner($attr, false);
    }
}
