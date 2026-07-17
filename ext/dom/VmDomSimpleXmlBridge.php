<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\simplexml\SimpleXmlNodeState;
use PHPCompiler\ext\simplexml\SimpleXmlRegistry;
use PHPCompiler\ext\simplexml\VmSimpleXml;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOM ↔ SimpleXML cross-extension bridge (php-src ext/dom/node.c + ext/simplexml/simplexml.c; #6057, #20137).
 *
 * php-src shares the same libxml node between wrappers. Here we keep peer links between
 * DomRegistry element ids and SimpleXmlNodeState objects so text/attribute mutations propagate.
 */
final class VmDomSimpleXmlBridge
{
    private const PEERS_KEY = '__phpc_dom_simplexml_peers';

    /** Re-entrancy guard while syncing Dom ↔ SimpleXML peers. */
    private static bool $syncing = false;

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
        if (VmDom::isDocument($domNode)) {
            $rootVar = $domNode->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $rootVar->type) {
                return null;
            }
            $domNode = $rootVar->toObject();
        }
        if (!VmDom::isElement($domNode)) {
            return null;
        }

        $state = self::simpleXmlStateFromDomElement($domNode);

        return self::wrapSimpleXml($class, $state);
    }

    /**
     * simplexml_import_dom(SimpleXMLElement) — new wrapper over the same node state
     * (php-src ext/simplexml/simplexml.c; #20291).
     */
    public static function importSimpleXmlElement(
        Context $ctx,
        ObjectEntry $sxe,
        ClassEntry $class
    ): ?ObjectEntry {
        if (!SimpleXmlRegistry::has($sxe)) {
            return null;
        }
        if (SimpleXmlRegistry::isAttributesView($sxe)) {
            return null;
        }
        $docKey = SimpleXmlRegistry::documentKey($sxe);
        if (SimpleXmlRegistry::isView($sxe)) {
            $elements = SimpleXmlRegistry::view($sxe);
            if ([] === $elements) {
                return null;
            }

            return VmSimpleXml::wrapIteratorNode($ctx, $class, $elements[0], $docKey);
        }

        return VmSimpleXml::wrapIteratorNode($ctx, $class, SimpleXmlRegistry::state($sxe), $docKey);
    }

    /** Class-hierarchy instanceof SimpleXMLElement (#20291). */
    public static function isSimpleXmlElementInstance(ObjectEntry $entry, Context $ctx): bool
    {
        $class = $entry->class;
        for ($guard = 0; null !== $class && $guard < 64; ++$guard) {
            if (VmSimpleXml::CLASS_LC === strtolower($class->name)) {
                return true;
            }
            if (null === $class->parentLc || !isset($ctx->classes[$class->parentLc])) {
                return false;
            }
            $class = $ctx->classes[$class->parentLc];
        }

        return false;
    }

    /**
     * After DOM element textContent / nodeValue write — mirror into linked SimpleXML state (#20137).
     */
    public static function syncSimpleXmlTextFromDom(ObjectEntry $domElement, string $value): void
    {
        if (self::$syncing) {
            return;
        }
        $sxe = self::sxePeer($domElement->id);
        if (null === $sxe) {
            return;
        }
        self::$syncing = true;
        try {
            $sxe->children = [];
            $sxe->text = $value;
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * After DOM attribute write/remove — mirror attribute map into linked SimpleXML state (#20137).
     */
    public static function syncSimpleXmlAttributesFromDom(ObjectEntry $domElement): void
    {
        if (self::$syncing) {
            return;
        }
        $sxe = self::sxePeer($domElement->id);
        if (null === $sxe) {
            return;
        }
        if (!DomRegistry::has($domElement)) {
            return;
        }
        self::$syncing = true;
        try {
            $sxe->attributes = DomRegistry::state($domElement)->attributes;
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * After SimpleXML text write — mirror into linked DOM element (#20137).
     */
    public static function syncDomTextFromSimpleXml(Context $ctx, SimpleXmlNodeState $sxe, string $value): void
    {
        if (self::$syncing) {
            return;
        }
        $domId = self::domPeerId($sxe);
        if (null === $domId) {
            return;
        }
        $dom = DomRegistry::entry($domId);
        if (null === $dom || !VmDom::isElement($dom)) {
            return;
        }
        self::$syncing = true;
        try {
            VmDom::writeTextContent($ctx, $dom, $value);
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * After SimpleXML attribute write — mirror into linked DOM element (#20137).
     */
    public static function syncDomAttributeFromSimpleXml(
        Context $ctx,
        SimpleXmlNodeState $sxe,
        string $name,
        string $value
    ): void {
        if (self::$syncing) {
            return;
        }
        $domId = self::domPeerId($sxe);
        if (null === $domId) {
            return;
        }
        $dom = DomRegistry::entry($domId);
        if (null === $dom || !VmDom::isElement($dom)) {
            return;
        }
        self::$syncing = true;
        try {
            VmDom::setAttributeNS($ctx, $dom, null, $name, $value);
        } finally {
            self::$syncing = false;
        }
    }

    public static function resetPeers(): void
    {
        unset($GLOBALS[self::PEERS_KEY]);
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

        // Link after initial attrs so setAttributeNS during build does not need a peer yet.
        self::linkPeers($element, $node);

        if ([] === $node->children) {
            if ('' !== $node->text) {
                // writeTextContent syncs back to the same SimpleXmlNodeState (no-op values).
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
        self::linkPeers($element, $node);

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

    private static function linkPeers(ObjectEntry $domElement, SimpleXmlNodeState $sxe): void
    {
        $bucket = &self::peersBucket();
        $bucket['dom_to_sxe'][$domElement->id] = $sxe;
        $bucket['sxe_to_dom'][spl_object_id($sxe)] = $domElement->id;
    }

    private static function sxePeer(int $domId): ?SimpleXmlNodeState
    {
        $bucket = self::peersBucket();
        $peer = $bucket['dom_to_sxe'][$domId] ?? null;

        return $peer instanceof SimpleXmlNodeState ? $peer : null;
    }

    private static function domPeerId(SimpleXmlNodeState $sxe): ?int
    {
        $bucket = self::peersBucket();
        $id = $bucket['sxe_to_dom'][spl_object_id($sxe)] ?? null;

        return \is_int($id) ? $id : null;
    }

    /** @return array{dom_to_sxe: array<int, SimpleXmlNodeState>, sxe_to_dom: array<int, int>} */
    private static function &peersBucket(): array
    {
        if (!isset($GLOBALS[self::PEERS_KEY]) || !\is_array($GLOBALS[self::PEERS_KEY])) {
            $GLOBALS[self::PEERS_KEY] = [
                'dom_to_sxe' => [],
                'sxe_to_dom' => [],
            ];
        }

        return $GLOBALS[self::PEERS_KEY];
    }

    public static function requireSimpleXmlElement(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($node) must be of type object, null given',
                $label
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($node) must be of type object, %s given',
                $label,
                EnumCaseSupport::typeNameForVariable($var)
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
