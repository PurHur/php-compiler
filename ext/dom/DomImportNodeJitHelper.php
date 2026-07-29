<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

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

    /** Dom\Document::importLegacyNode() — compiled JIT/AOT (#20940). */
    public static function importLegacyNodeArgv(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node,
        int $deep
    ): ObjectEntry {
        return VmDom::importLegacyNode($ctx, $document, $node, 0 !== $deep)->toObject();
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

    /** DOMDocument::createAttributeNS() — user-script AOT (#19265, #24804). */
    public static function createAttributeNSArgv(
        Context $ctx,
        ObjectEntry $document,
        string $namespace,
        string $qualifiedName
    ): ObjectEntry {
        $ns = '' === $namespace ? null : $namespace;
        $var = VmDom::documentCreateAttributeNS($ctx, $document, $ns, $qualifiedName, null);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException('Namespace Error', DomExceptionConstants::NAMESPACE_ERR);
        }

        return $var->toObject();
    }

    /** DOMDocument::createAttribute() — JIT/AOT (#20676, #24804). */
    public static function createAttributeArgv(
        Context $ctx,
        ObjectEntry $document,
        string $name
    ): ObjectEntry {
        $var = VmDom::createAttribute($ctx, $name, $document);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Invalid Character Error',
                DomExceptionConstants::INVALID_CHARACTER_ERR
            );
        }

        return $var->toObject();
    }

    /** DOMElement::setAttributeNode() — JIT/AOT (#20676). */
    public static function setAttributeNodeArgv(ObjectEntry $element, ObjectEntry $attr): ?ObjectEntry
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($element->id) ?? $element;
        $attrCanon = DomRegistry::entry($attr->id) ?? $attr;
        $var = VmDom::setAttributeNode($ctx, $canonical, $attrCanon);
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }
}
