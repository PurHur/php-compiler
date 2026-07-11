<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\simplexml\SimpleXmlNodeState;
use PHPCompiler\ext\simplexml\SimpleXmlRegistry;
use PHPCompiler\ext\simplexml\VmSimpleXml;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOM ↔ SimpleXML cross-extension bridge (php-src ext/dom/node.c + ext/simplexml/simplexml.c; #6057).
 */
final class VmDomSimpleXmlBridge
{
    public static function importSimpleXml(Context $ctx, ObjectEntry $sxe): ObjectEntry
    {
        VmSimpleXml::requireElement($sxe, 'dom_import_simplexml()');
        $nodeState = SimpleXmlRegistry::state($sxe);

        $document = self::createEmptyDocument($ctx);
        $root = self::domElementFromSimpleXmlState($ctx, $document, $nodeState);
        VmDom::appendChild($ctx, $document, $root);

        return $root;
    }

    public static function importDom(Context $ctx, ObjectEntry $domNode, ClassEntry $class): ?ObjectEntry
    {
        if (!DomRegistry::has($domNode)) {
            return null;
        }
        if (!VmDom::isElement($domNode)) {
            return null;
        }

        $state = self::simpleXmlStateFromDomElement($domNode);

        return self::wrapSimpleXml($class, $state);
    }

    private static function createEmptyDocument(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocument is not registered in this compiler build');
        }

        $document = new ObjectEntry($class);
        $document->constructed = true;
        VmDom::ensureDocument($document);
        VmDom::ensureChildNodesList($ctx, $document);

        return $document;
    }

    private static function domElementFromSimpleXmlState(
        Context $ctx,
        ObjectEntry $document,
        SimpleXmlNodeState $node
    ): ObjectEntry {
        $element = VmDom::createElement($ctx, $node->name, $document)->toObject();

        foreach ($node->attributes as $name => $value) {
            VmDom::setAttributeNS($ctx, $element, null, $name, $value);
        }

        if ([] === $node->children) {
            if ('' !== $node->text) {
                VmDom::writeTextContent($ctx, $element, $node->text);
            }

            return $element;
        }

        if ('' !== $node->text) {
            $textNode = VmDom::createTextNode($ctx, $node->text, $document);
            VmDom::appendChild($ctx, $element, $textNode);
        }

        foreach ($node->children as $child) {
            $childElement = self::domElementFromSimpleXmlState($ctx, $document, $child);
            VmDom::appendChild($ctx, $element, $childElement);
        }

        return $element;
    }

    private static function simpleXmlStateFromDomElement(ObjectEntry $element): SimpleXmlNodeState
    {
        $state = DomRegistry::state($element);
        $node = new SimpleXmlNodeState($state->nodeName, $state->attributes);
        $node->text = self::directTextContent($element);

        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isElement($child)) {
                $node->children[] = self::simpleXmlStateFromDomElement($child);
            }
        }

        return $node;
    }

    private static function directTextContent(ObjectEntry $element): string
    {
        $state = DomRegistry::state($element);
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isTextNode($child)) {
                $parts[] = DomRegistry::state($child)->textContent ?? '';
            }
        }

        return implode('', $parts);
    }

    private static function wrapSimpleXml(ClassEntry $class, SimpleXmlNodeState $state): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        SimpleXmlRegistry::attach($entry, $state);

        return $entry;
    }

    public static function requireSimpleXmlElement(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($node) is not a valid node type',
                $label
            ));
        }
        $object = $var->toObject();
        if (VmSimpleXml::CLASS_LC !== strtolower($object->class->name)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($node) is not a valid node type',
                $label
            ));
        }
        if (!SimpleXmlRegistry::has($object)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($node) is not a valid node type',
                $label
            ));
        }

        return $object;
    }

    public static function resolveSimpleXmlClass(Context $ctx, ?string $className): ClassEntry
    {
        $className = null === $className || '' === $className ? 'SimpleXMLElement' : $className;
        $class = $ctx->classes[strtolower($className)] ?? null;
        if (null === $class) {
            throw new \TypeError(sprintf(
                'simplexml_import_dom(): Argument #2 ($class_name) must be a valid class name, %s given',
                $className
            ));
        }
        if (VmSimpleXml::CLASS_LC !== strtolower($class->name)
            && VmSimpleXml::CLASS_LC !== strtolower($class->parentLc ?? '')) {
            throw new \TypeError(sprintf(
                'simplexml_import_dom(): Argument #2 ($class_name) must be a class name derived from SimpleXMLElement, %s given',
                $className
            ));
        }

        return $class;
    }
}
