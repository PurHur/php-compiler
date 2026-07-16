<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::importNode() for compiled JIT/AOT modules (#19212).
 *
 * SSOT: {@see VmDom::importNode()}
 * php-src: ext/dom/php_dom.c — dom_document_import_node
 */
final class DomImportNodeJitHelper
{
    public static function importNodeArgv(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node,
        int $deep
    ): ObjectEntry {
        return VmDom::importNode($ctx, $document, $node, 0 !== $deep)->toObject();
    }

    public static function getAttributeArgv(ObjectEntry $element, string $name): string
    {
        return VmDom::getAttribute($element, $name);
    }

    /** DOMElement::getAttributeNodeNS() — user-script AOT (#19265). */
    public static function getAttributeNodeNSArgv(
        ObjectEntry $element,
        string $namespace,
        string $localName
    ): ?ObjectEntry {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($element->id) ?? $element;
        $ns = '' === $namespace ? null : $namespace;
        $var = VmDom::getAttributeNodeNS($ctx, $canonical, $ns, $localName);
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }

    /** DOMElement::setAttributeNodeNS() — user-script AOT (#19265). */
    public static function setAttributeNodeNSArgv(ObjectEntry $element, ObjectEntry $attr): ?ObjectEntry
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($element->id) ?? $element;
        $attrCanon = DomRegistry::entry($attr->id) ?? $attr;
        $var = VmDom::setAttributeNodeNS($ctx, $canonical, $attrCanon);
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }

    /** DOMDocument::createAttributeNS() — user-script AOT (#19265). */
    public static function createAttributeNSArgv(
        Context $ctx,
        ObjectEntry $document,
        string $namespace,
        string $qualifiedName
    ): ObjectEntry {
        $ns = '' === $namespace ? null : $namespace;
        $var = VmDom::documentCreateAttributeNS($ctx, $document, $ns, $qualifiedName, null);
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException('Invalid State Error', 11);
        }

        return $var->toObject();
    }
}
