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

    /**
     * @param bool $modern When true, wrap as Dom\Element under Dom\XMLDocument
     *                     (php-src Dom\import_simplexml / PHP_LIBXML_CLASS_MODERN; #20711).
     */
    public static function importSimpleXml(Context $ctx, ObjectEntry $sxe, bool $modern = false): ObjectEntry
    {
        $label = $modern ? 'Dom\\import_simplexml' : 'dom_import_simplexml';
        VmSimpleXml::requireElement($sxe, $label);
        // Named-child / children / multi views attach the parent (or collection) as
        // registry state; php-src exports the first matching element node (#20697, re-#20137).
        $nodeState = self::resolveExportElementState($sxe, $label);

        $document = self::createEmptyDocument($ctx, $modern);
        // Ancestor xmlns must stay in scope when exporting a child node (#22738).
        $parentScope = self::parentNamespaceScopeForExport($sxe, $nodeState);
        $root = self::domElementFromSimpleXmlState($ctx, $document, $nodeState, $parentScope);
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
        try {
            $state = self::resolveExportElementState($sxe, 'simplexml_import_dom');
        } catch (\ValueError) {
            return null;
        }

        return VmSimpleXml::wrapIteratorNode($ctx, $class, $state, $docKey);
    }

    /**
     * Element node exported by php-src `dom_import_simplexml` / shared-node bridges.
     *
     * After #20489, `$sxe->child` is a named-child view whose registry state is the
     * **parent**; export the first matching child (php-src sxe property ptr).
     */
    private static function resolveExportElementState(ObjectEntry $sxe, string $label): SimpleXmlNodeState
    {
        if (SimpleXmlRegistry::isAttributesView($sxe)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #1 ($node) is not a valid node type',
                $label
            ));
        }
        if (SimpleXmlRegistry::isNamedChildView($sxe)) {
            $matches = VmSimpleXml::namedChildViewElements($sxe);
            if ([] === $matches) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #1 ($node) is not a valid node type',
                    $label
                ));
            }

            return $matches[0];
        }
        if (SimpleXmlRegistry::isChildrenView($sxe)) {
            $matches = VmSimpleXml::childrenViewElements($sxe);
            if ([] === $matches) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #1 ($node) is not a valid node type',
                    $label
                ));
            }

            return $matches[0];
        }
        if (SimpleXmlRegistry::isView($sxe)) {
            $elements = SimpleXmlRegistry::view($sxe);
            if ([] === $elements) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #1 ($node) is not a valid node type',
                    $label
                ));
            }

            return $elements[0];
        }

        return SimpleXmlRegistry::state($sxe);
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
            $sxe->replaceText($value);
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

    private static function createEmptyDocument(Context $ctx, bool $modern = false): ObjectEntry
    {
        if ($modern) {
            // Living Dom\XMLDocument so createElement resolves Dom\Element (#20711).
            return VmDomLiving::allocateXmlDocument($ctx);
        }

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

    /**
     * In-scope xmlns map on the parent of $target (empty when $target is the document root).
     *
     * @return array<string, string> prefix => URI
     */
    private static function parentNamespaceScopeForExport(
        ObjectEntry $sxe,
        SimpleXmlNodeState $target
    ): array {
        $docKey = SimpleXmlRegistry::documentKey($sxe);
        try {
            $root = SimpleXmlRegistry::rootState($docKey);
        } catch (\Throwable) {
            return [];
        }

        return self::parentNamespaceScopeWalk($root, $target, []) ?? [];
    }

    /**
     * @param array<string, string> $parentScope
     *
     * @return array<string, string>|null
     */
    private static function parentNamespaceScopeWalk(
        SimpleXmlNodeState $node,
        SimpleXmlNodeState $target,
        array $parentScope
    ): ?array {
        if ($node === $target) {
            return $parentScope;
        }
        $scopeAtNode = self::scopeAfterSimpleXmlNode($node, $parentScope);
        foreach ($node->children as $child) {
            $found = self::parentNamespaceScopeWalk($child, $target, $scopeAtNode);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Build a DOMElement whose namespaceURI / localName / prefix match the SXE node
     * (php-src shares the libxml xmlNode; #22738).
     *
     * @param array<string, string> $parentScope prefix => URI in scope on the parent
     */
    private static function domElementFromSimpleXmlState(
        Context $ctx,
        ObjectEntry $document,
        SimpleXmlNodeState $node,
        array $parentScope = []
    ): ObjectEntry {
        $scope = self::scopeAfterSimpleXmlNode($node, $parentScope);
        $namespaceUri = self::resolveSimpleXmlElementNamespaceUri($node, $scope);
        if ('' !== $namespaceUri) {
            // Prefixed or default-NS elements must use createElementNS so localName/prefix
            // split correctly (createElement stores the QName as localName).
            $element = VmDom::createElementNS($ctx, $namespaceUri, $node->name, $document)->toObject();
        } else {
            $element = VmDom::createElement($ctx, $node->name, $document)->toObject();
        }

        // php-src setAttributeNS: default xmlns accepts null NS; xmlns:* requires
        // http://www.w3.org/2000/xmlns/ (null → Namespace Error; #25124 / re-#22738).
        foreach ($node->attributes as $name => $value) {
            $attrNs = null;
            if ('xmlns' === $name || str_starts_with($name, 'xmlns:')) {
                $attrNs = 'http://www.w3.org/2000/xmlns/';
            }
            VmDom::setAttributeNS($ctx, $element, $attrNs, $name, $value);
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
            $childElement = self::domElementFromSimpleXmlState($ctx, $document, $child, $scope);
            VmDom::appendChild($ctx, $element, $childElement);
        }

        return $element;
    }

    /**
     * Merge xmlns / xmlns:* decls on $node into the parent in-scope map
     * (php-src xmlGetNsList / sxe scope walk).
     *
     * @param array<string, string> $parentScope
     *
     * @return array<string, string>
     */
    private static function scopeAfterSimpleXmlNode(SimpleXmlNodeState $node, array $parentScope): array
    {
        $scope = $parentScope;
        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name) {
                $scope[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $scope[substr($name, 6)] = $value;
            }
        }

        return $scope;
    }

    /**
     * Namespace URI for an SXE element in the given in-scope xmlns map
     * (mirrors VmSimpleXml::resolveElementNamespaceUri).
     *
     * @param array<string, string> $inScope
     */
    private static function resolveSimpleXmlElementNamespaceUri(
        SimpleXmlNodeState $element,
        array $inScope
    ): string {
        if (isset($element->attributes['xmlns'])) {
            return $element->attributes['xmlns'];
        }
        $colon = strpos($element->name, ':');
        if (false !== $colon) {
            $prefix = substr($element->name, 0, $colon);
            if ('' !== $prefix) {
                if (isset($element->attributes['xmlns:'.$prefix])) {
                    return $element->attributes['xmlns:'.$prefix];
                }

                return $inScope[$prefix] ?? '';
            }
        }

        return $inScope[''] ?? '';
    }

    private static function simpleXmlStateFromDomElement(ObjectEntry $element): SimpleXmlNodeState
    {
        $state = DomRegistry::state($element);
        $node = new SimpleXmlNodeState($state->nodeName, $state->attributes);
        self::linkPeers($element, $node);

        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (VmDom::isElement($child)) {
                $node->appendElement(self::simpleXmlStateFromDomElement($child));
            } elseif (VmDom::isTextOrCdataNode($child)) {
                $node->appendText(DomRegistry::state($child)->textContent ?? '');
            }
        }

        return $node;
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
