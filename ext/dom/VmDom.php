<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOM factory + serialization in PHP (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP: no runtime/*.c growth — tree state in {@see DomRegistry}.
 */
final class VmDom
{
    public const CLASS_IMPLEMENTATION = 'domimplementation';

    public const CLASS_DOCUMENT = 'domdocument';

    public const CLASS_DOCUMENT_TYPE = 'domdocumenttype';

    public const CLASS_ELEMENT = 'domelement';

    public const CLASS_DOCUMENT_FRAGMENT = 'domdocumentfragment';

    public const CLASS_NODE = 'domnode';

    public const CLASS_NODE_LIST = 'domnodelist';

    public const PROP_FORMAT_OUTPUT = 'formatOutput';

    public const PROP_VALIDATE_ON_PARSE = 'validateOnParse';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public const PROP_NODE_NAME = 'nodeName';

    public const PROP_FIRST_CHILD = 'firstChild';

    public const PROP_LAST_CHILD = 'lastChild';

    public const PROP_CHILD_NODES = 'childNodes';

    public const PROP_NEXT_SIBLING = 'nextSibling';

    public const PROP_PARENT_NODE = 'parentNode';

    public const PROP_LENGTH = 'length';

    public const PROP_NAME = 'name';

    public const PROP_PUBLIC_ID = 'publicId';

    public const PROP_SYSTEM_ID = 'systemId';

    public static function registerClasses(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_IMPLEMENTATION])) {
            return;
        }

        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $strProto = new Variable(Variable::TYPE_STRING);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $objProto = new Variable(Variable::TYPE_OBJECT);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $node = new ClassEntry('DOMNode');
        $node->isInternal = true;
        $node->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_FIRST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_LAST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_CHILD_NODES, null, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_NEXT_SIBLING, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_PARENT_NODE, $nullProto, $objProto);
        $node->methods['clonenode'] = new NodeCloneNode();
        $node->methodVisibility['clonenode'] = $pub;
        $ctx->classes[self::CLASS_NODE] = $node;

        $nodeList = new ClassEntry('DOMNodeList');
        $nodeList->isInternal = true;
        $nodeList->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        $nodeList->methods['item'] = new NodeListItem();
        $nodeList->methodVisibility['item'] = $pub;
        $ctx->classes[self::CLASS_NODE_LIST] = $nodeList;

        $impl = new ClassEntry('DOMImplementation');
        $impl->isInternal = true;
        $impl->methods['createdocument'] = new ImplementationCreateDocument();
        $impl->methodVisibility['createdocument'] = $pub;
        $impl->methods['createdocumenttype'] = new ImplementationCreateDocumentType();
        $impl->methodVisibility['createdocumenttype'] = $pub;
        $impl->methods['hasfeature'] = new ImplementationHasFeature();
        $impl->methodVisibility['hasfeature'] = $pub;
        $ctx->classes[self::CLASS_IMPLEMENTATION] = $impl;

        $doctype = new ClassEntry('DOMDocumentType');
        $doctype->isInternal = true;
        $doctype->parentLc = self::CLASS_NODE;
        $doctype->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_NAME, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_PUBLIC_ID, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_SYSTEM_ID, null, $strProto);
        $ctx->classes[self::CLASS_DOCUMENT_TYPE] = $doctype;

        $document = new ClassEntry('DOMDocument');
        $document->isInternal = true;
        $document->parentLc = self::CLASS_NODE;
        $document->properties[] = new ClassProperty(self::PROP_FORMAT_OUTPUT, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_VALIDATE_ON_PARSE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        $document->methods['loadxml'] = new DocumentLoadXML();
        $document->methodVisibility['loadxml'] = $pub;
        $document->methods['createelement'] = new DocumentCreateElement();
        $document->methodVisibility['createelement'] = $pub;
        $document->methods['createdocumentfragment'] = new DocumentCreateDocumentFragment();
        $document->methodVisibility['createdocumentfragment'] = $pub;
        $document->methods['appendchild'] = new DocumentAppendChild();
        $document->methodVisibility['appendchild'] = $pub;
        $document->methods['savexml'] = new DocumentSaveXML();
        $document->methodVisibility['savexml'] = $pub;
        $document->methods['getelementsbytagname'] = new DocumentGetElementsByTagName();
        $document->methodVisibility['getelementsbytagname'] = $pub;
        $document->methods['getelementbyid'] = new DocumentGetElementById();
        $document->methodVisibility['getelementbyid'] = $pub;
        $ctx->classes[self::CLASS_DOCUMENT] = $document;

        $element = new ClassEntry('DOMElement');
        $element->isInternal = true;
        $element->parentLc = self::CLASS_NODE;
        $element->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $element->methods['appendchild'] = new ElementAppendChild();
        $element->methodVisibility['appendchild'] = $pub;
        $ctx->classes[self::CLASS_ELEMENT] = $element;

        $fragment = new ClassEntry('DOMDocumentFragment');
        $fragment->isInternal = true;
        $fragment->parentLc = self::CLASS_NODE;
        $fragment->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $fragment->methods['appendchild'] = new FragmentAppendChild();
        $fragment->methodVisibility['appendchild'] = $pub;
        $ctx->classes[self::CLASS_DOCUMENT_FRAGMENT] = $fragment;
    }

    public static function createDocumentType(
        Context $ctx,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): Variable {
        $class = $ctx->classes[self::CLASS_DOCUMENT_TYPE] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocumentType is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_TYPE_NODE;
        $state->nodeName = $qualifiedName;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        DomRegistry::attach($entry, $state);
        self::initDocumentTypePropertySlots($entry, $qualifiedName, $publicId, $systemId);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createDocument(
        Context $ctx,
        ?string $namespaceUri,
        string $qualifiedName,
        ?ObjectEntry $doctype
    ): Variable {
        if (null !== $doctype && !self::isDocumentType($doctype)) {
            throw new \TypeError(
                'DOMImplementation::createDocument(): Argument #3 ($doctype) must be of type DOMDocumentType or null'
            );
        }

        $class = $ctx->classes[self::CLASS_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_FORMAT_OUTPUT)->bool(false);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
        $state->nodeName = '#document';
        $state->namespaceUri = $namespaceUri;
        $state->documentElementName = $qualifiedName;
        if (null !== $doctype) {
            $dt = DomRegistry::state($doctype);
            $state->doctypeName = $dt->nodeName;
            $state->doctypePublicId = $dt->publicId;
            $state->doctypeSystemId = $dt->systemId;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hasFeature(string $feature, string $version): bool
    {
        $feature = strtoupper($feature);
        $version = trim($version);

        if ('XML' !== $feature && 'Core' !== $feature) {
            return false;
        }

        return '1.0' === $version || '2.0' === $version;
    }

    public static function ensureDocument(ObjectEntry $document): DomNodeState
    {
        if (!DomRegistry::has($document)) {
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
            $state->nodeName = '#document';
            DomRegistry::attach($document, $state);
            if (!$document->hasProperty(self::PROP_FORMAT_OUTPUT)) {
                $document->getProperty(self::PROP_FORMAT_OUTPUT)->bool(false);
            }
            self::initNodePropertySlots($document);
        }

        return DomRegistry::state($document);
    }

    public static function createElement(Context $ctx, string $name): Variable
    {
        $class = $ctx->classes[self::CLASS_ELEMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMElement is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $name;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createDocumentFragment(Context $ctx): Variable
    {
        $class = $ctx->classes[self::CLASS_DOCUMENT_FRAGMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocumentFragment is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#document-fragment');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_FRAG_NODE;
        $state->nodeName = '#document-fragment';
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function loadXML(Context $ctx, ObjectEntry $document, string $xml): bool
    {
        self::ensureDocument($document);

        $trimmed = trim($xml);
        $idAttrByElement = self::parseDoctypeIdAttributes($trimmed);
        $elementXml = self::stripDoctype($trimmed);
        if (!VmXml::isWellFormed($elementXml)) {
            return false;
        }
        $root = self::parseElementTree($ctx, $elementXml);
        if (null === $root) {
            return false;
        }

        $state = DomRegistry::state($document);
        $state->childIds = [];
        $state->idAttrByElement = $idAttrByElement;
        $state->elementIds = [];
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        self::linkChildToParent($root, null);
        self::syncSubtree($ctx, $root);
        self::reindexDocumentIds($document, $root);

        return true;
    }

    public static function getElementById(ObjectEntry $document, string $elementId): ?ObjectEntry
    {
        self::ensureDocument($document);
        $state = DomRegistry::state($document);
        $objectId = $state->elementIds[$elementId] ?? null;
        if (null === $objectId) {
            return null;
        }

        return DomRegistry::entry($objectId);
    }

    private static function reindexDocumentIds(ObjectEntry $document, ObjectEntry $root): void
    {
        $docState = DomRegistry::state($document);
        $docState->elementIds = [];
        if (!self::documentValidateOnParse($document)) {
            return;
        }
        if ([] === $docState->idAttrByElement) {
            return;
        }
        self::indexElementIdsRecursive($document, $root);
    }

    private static function documentValidateOnParse(ObjectEntry $document): bool
    {
        if (!$document->hasProperty(self::PROP_VALIDATE_ON_PARSE)) {
            return false;
        }
        $prop = $document->getProperty(self::PROP_VALIDATE_ON_PARSE)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $prop->type) {
            return false;
        }
        try {
            return $prop->toBool();
        } catch (\Error) {
            return false;
        }
    }

    private static function indexElementIdsRecursive(ObjectEntry $document, ObjectEntry $node): void
    {
        if (!self::isElement($node)) {
            return;
        }
        $docState = DomRegistry::state($document);
        $nodeState = DomRegistry::state($node);
        $idAttr = $docState->idAttrByElement[$nodeState->nodeName] ?? null;
        if (null !== $idAttr) {
            $value = $nodeState->attributes[$idAttr] ?? null;
            if (null !== $value && '' !== $value) {
                $docState->elementIds[$value] = $node->id;
            }
        }
        foreach ($nodeState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::indexElementIdsRecursive($document, $child);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseDoctypeIdAttributes(string $xml): array
    {
        $idAttrs = [];
        if (!preg_match('/<!DOCTYPE\s+\S+\s*\[(.*)\]\s*>/s', $xml, $doctype)) {
            return $idAttrs;
        }
        if (!preg_match_all('/<!ATTLIST\s+(\S+)\s+(\S+)\s+ID\b/', $doctype[1], $matches, PREG_SET_ORDER)) {
            return $idAttrs;
        }
        foreach ($matches as $match) {
            $idAttrs[$match[1]] = $match[2];
        }

        return $idAttrs;
    }

    private static function stripDoctype(string $xml): string
    {
        $stripped = preg_replace('/^\s*<\?xml[^?]*\?>\s*/s', '', $xml) ?? $xml;
        $stripped = preg_replace('/^\s*<!DOCTYPE\s+\S+\s*\[[^\]]*\]\s*>\s*/s', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/s', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttributes(string $attrString): array
    {
        $attrs = [];
        if ('' === $attrString) {
            return $attrs;
        }
        if (preg_match_all('/\s([A-Za-z_][\w:.-]*)\s*=\s*"([^"]*)"/', $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[$match[1]] = $match[2];
            }
        }

        return $attrs;
    }

    public static function appendChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        if (self::isDocumentFragment($child)) {
            return self::appendFragmentChildren($ctx, $parent, $child);
        }

        if (!self::isElement($child)) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL !== $existing->type) {
                $parentState->childIds[] = $child->id;
                self::linkChildToParent($child, $parent);
                self::syncSubtree($ctx, $parent);

                return $child;
            }
            $parentState->documentElementName = DomRegistry::state($child)->nodeName;
            $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);
            self::linkChildToParent($child, null);
            self::syncSubtree($ctx, $child);

            return $child;
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $parentState->nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState->childIds[] = $child->id;
        self::linkChildToParent($child, $parent);
        self::syncSubtree($ctx, $parent);

        return $child;
    }

    private static function appendFragmentChildren(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $fragment
    ): ObjectEntry {
        if (!self::isDocumentFragment($fragment)) {
            throw new \LogicException('appendFragmentChildren() expects a DOMDocumentFragment');
        }

        $fragState = DomRegistry::state($fragment);
        $childIds = $fragState->childIds;
        $fragState->childIds = [];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::linkChildToParent($child, null);
            self::appendChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $fragment);

        return $fragment;
    }

    public static function saveXML(ObjectEntry $document): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveXML() called on non-document node in this compiler build');
        }

        $lines = ['<?xml version="1.0"?>'];

        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? ''
            );
        }

        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $lines[] = self::serializeElement($rootVar->toObject());
        } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
            $lines[] = '<'.self::escapeName($state->documentElementName).'/>';
        }

        return implode("\n", $lines)."\n";
    }

    private static function elementVariable(ObjectEntry $entry): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    private static function parseElementTree(Context $ctx, string $elementXml): ?ObjectEntry
    {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            $entry = self::createElement($ctx, $selfClose[1])->toObject();
            DomRegistry::state($entry)->attributes = self::parseAttributes($selfClose[2] ?? '');

            return $entry;
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $entry = self::createElement($ctx, $matches[1])->toObject();
        $state = DomRegistry::state($entry);
        $state->attributes = self::parseAttributes($matches[2] ?? '');
        $pos = 0;
        $inner = $matches[3];
        $len = \strlen($inner);
        while ($pos < $len) {
            if (preg_match('/\G\s+/s', $inner, $m, 0, $pos)) {
                $pos += \strlen($m[0]);

                continue;
            }
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $pos = (false === $next) ? $len : $next;

                continue;
            }
            $end = self::findElementEnd($inner, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($inner, $pos, $end - $pos);
            $child = self::parseElementTree($ctx, $childXml);
            if (null === $child) {
                return null;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $entry);
            $pos = $end;
        }

        self::syncSubtree($ctx, $entry);

        return $entry;
    }

    /** @return null|int byte offset after one element starting at $pos */
    private static function findElementEnd(string $content, int $pos): ?int
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $pos)) {
            return $pos + \strlen($selfClose[0]);
        }
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $open, 0, $pos)) {
            return null;
        }

        /** @var list<string> $stack */
        $stack = [$open[1]];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $content, $close, 0, $scan)) {
                $name = $close[1];
                if ([] === $stack || end($stack) !== $name) {
                    return null;
                }
                array_pop($stack);
                $scan += \strlen($close[0]);
                if ([] === $stack) {
                    return $scan;
                }

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $nested, 0, $scan)) {
                $stack[] = $nested[1];
                $scan += \strlen($nested[0]);

                continue;
            }
            ++$scan;
        }

        return null;
    }

    private static function serializeElement(ObjectEntry $entry): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        if ([] === $state->childIds) {
            return '<'.$name.'/>';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeElement($child);
            }
        }

        return '<'.$name.'>'.implode('', $parts).'</'.$name.'>';
    }

    /**
     * @return list<int> matching element object ids in document order (php-src dom_document_get_elements_by_tag_name)
     */
    public static function collectElementsByTagName(ObjectEntry $node, string $tagName): array
    {
        $matches = [];
        $want = '*' === $tagName ? null : $tagName;
        self::collectElementsByTagNameRecursive($node, $want, $matches);

        return $matches;
    }

    public static function getElementsByTagName(Context $ctx, ObjectEntry $document, string $tagName): Variable
    {
        self::ensureDocument($document);
        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            return self::createNodeList($ctx, []);
        }

        return self::createNodeList($ctx, self::collectElementsByTagName($rootVar->toObject(), $tagName));
    }

    public static function nodeListItem(ObjectEntry $nodeList, int $index): ?ObjectEntry
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::item() called on non-nodelist in this compiler build');
        }
        $ids = DomRegistry::state($nodeList)->listNodeIds;
        if (!isset($ids[$index])) {
            return null;
        }

        return DomRegistry::entry($ids[$index]);
    }

    /**
     * @param list<int> $nodeIds
     */
    public static function createNodeList(Context $ctx, array $nodeIds): Variable
    {
        $class = $ctx->classes[self::CLASS_NODE_LIST] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMNodeList is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NODELIST;
        $state->nodeName = '#nodelist';
        $state->listNodeIds = $nodeIds;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function isNodeList(ObjectEntry $entry): bool
    {
        return self::CLASS_NODE_LIST === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_NODELIST === DomRegistry::state($entry)->nodeType;
    }

    private static function initDocumentTypePropertySlots(
        ObjectEntry $entry,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): void {
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_PUBLIC_ID)->string($publicId);
        $entry->getProperty(self::PROP_SYSTEM_ID)->string($systemId);
        self::initNodePropertySlots($entry);
    }

    private static function initNodePropertySlots(ObjectEntry $entry): void
    {
        if (!$entry->hasProperty(self::PROP_FIRST_CHILD)) {
            $entry->allocateProperty(self::PROP_FIRST_CHILD)->null();
        }
        if (!$entry->hasProperty(self::PROP_LAST_CHILD)) {
            $entry->allocateProperty(self::PROP_LAST_CHILD)->null();
        }
        if (!$entry->hasProperty(self::PROP_NEXT_SIBLING)) {
            $entry->allocateProperty(self::PROP_NEXT_SIBLING)->null();
        }
        if (!$entry->hasProperty(self::PROP_PARENT_NODE)) {
            $entry->allocateProperty(self::PROP_PARENT_NODE)->null();
        }
        if (!$entry->hasProperty(self::PROP_CHILD_NODES)) {
            $entry->allocateProperty(self::PROP_CHILD_NODES)->null();
        }
    }

    private static function linkChildToParent(ObjectEntry $child, ?ObjectEntry $parent): void
    {
        $childState = DomRegistry::state($child);
        $childState->parentId = null !== $parent ? $parent->id : null;
    }

    private static function syncSubtree(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        self::syncNodeLinks($ctx, $node);
        $state = DomRegistry::state($node);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::syncSubtree($ctx, $child);
            }
        }
    }

    private static function syncNodeLinks(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        self::initNodePropertySlots($node);
        $state = DomRegistry::state($node);

        $parentVar = $node->getProperty(self::PROP_PARENT_NODE);
        if (null !== $state->parentId) {
            $parent = DomRegistry::entry($state->parentId);
            if (null !== $parent) {
                $parentVar->object($parent);
            } else {
                $parentVar->null();
            }
        } else {
            $parentVar->null();
        }

        $firstVar = $node->getProperty(self::PROP_FIRST_CHILD);
        $lastVar = $node->getProperty(self::PROP_LAST_CHILD);
        if ([] === $state->childIds) {
            $firstVar->null();
            $lastVar->null();
        } else {
            $first = DomRegistry::entry($state->childIds[0]);
            $last = DomRegistry::entry($state->childIds[\count($state->childIds) - 1]);
            if (null !== $first) {
                $firstVar->object($first);
            } else {
                $firstVar->null();
            }
            if (null !== $last) {
                $lastVar->object($last);
            } else {
                $lastVar->null();
            }
        }

        $childNodesVar = $node->getProperty(self::PROP_CHILD_NODES);
        if (null !== $state->childNodesListId) {
            $list = DomRegistry::entry($state->childNodesListId);
            if (null !== $list) {
                self::updateNodeListMembers($list, $state->childIds);
                $childNodesVar->object($list);

                return;
            }
        }
        if (null === $state->childNodesListId && Variable::TYPE_OBJECT === $childNodesVar->resolveIndirect()->type) {
            $existing = $childNodesVar->resolveIndirect()->toObject();
            if (self::isNodeList($existing)) {
                $state->childNodesListId = $existing->id;
                self::updateNodeListMembers($existing, $state->childIds);
                $childNodesVar->object($existing);

                return;
            }
        }
        if (null === $node->class->parentLc
            && !self::isElement($node)
            && !self::isDocument($node)
            && !self::isDocumentFragment($node)
        ) {
            return;
        }
        $listVar = self::createNodeList($ctx, $state->childIds);
        $list = $listVar->toObject();
        $state->childNodesListId = $list->id;
        $childNodesVar->copyFrom($listVar);

        foreach ($state->childIds as $index => $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::initNodePropertySlots($child);
            $siblingVar = $child->getProperty(self::PROP_NEXT_SIBLING);
            $nextId = $state->childIds[$index + 1] ?? null;
            if (null !== $nextId) {
                $next = DomRegistry::entry($nextId);
                if (null !== $next) {
                    $siblingVar->object($next);
                } else {
                    $siblingVar->null();
                }
            } else {
                $siblingVar->null();
            }
        }
    }

    /** @param list<int> $nodeIds */
    private static function updateNodeListMembers(ObjectEntry $nodeList, array $nodeIds): void
    {
        if (!self::isNodeList($nodeList)) {
            return;
        }
        $state = DomRegistry::state($nodeList);
        $state->listNodeIds = $nodeIds;
        $nodeList->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));
    }

    /**
     * @param list<int> $matches
     */
    private static function collectElementsByTagNameRecursive(
        ObjectEntry $node,
        ?string $want,
        array &$matches
    ): void {
        if (self::isElement($node)) {
            $name = DomRegistry::state($node)->nodeName;
            if (null === $want || $name === $want) {
                $matches[] = $node->id;
            }
        }
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementsByTagNameRecursive($child, $want, $matches);
            }
        }
    }

    public static function isElement(ObjectEntry $entry): bool
    {
        return self::CLASS_ELEMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_ELEMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isDocument(ObjectEntry $entry): bool
    {
        return self::CLASS_DOCUMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isDocumentFragment(ObjectEntry $entry): bool
    {
        return self::CLASS_DOCUMENT_FRAGMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_FRAG_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isAppendableNode(ObjectEntry $entry): bool
    {
        return self::isElement($entry) || self::isDocumentFragment($entry);
    }

    public static function isCloneableNode(ObjectEntry $entry): bool
    {
        return self::isElement($entry) || self::isDocumentFragment($entry);
    }

    public static function cloneNode(Context $ctx, ObjectEntry $source, bool $deep): Variable
    {
        if (!self::isCloneableNode($source)) {
            throw new \TypeError('DOMNode::cloneNode() must be called on a DOMNode instance');
        }

        $cloned = self::cloneNodeEntry($ctx, $source, $deep);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($cloned);

        return $var;
    }

    private static function cloneNodeEntry(Context $ctx, ObjectEntry $source, bool $deep): ObjectEntry
    {
        $sourceState = DomRegistry::state($source);
        if (self::isElement($source)) {
            $cloned = self::createElement($ctx, $sourceState->nodeName)->toObject();
        } elseif (self::isDocumentFragment($source)) {
            $cloned = self::createDocumentFragment($ctx)->toObject();
        } else {
            throw new \DOMException('Not supported cloneNode for this node type in this compiler build');
        }

        self::linkChildToParent($cloned, null);
        if ($deep) {
            $cloneState = DomRegistry::state($cloned);
            foreach ($sourceState->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null === $child || !self::isCloneableNode($child)) {
                    continue;
                }
                $clonedChild = self::cloneNodeEntry($ctx, $child, true);
                $cloneState->childIds[] = $clonedChild->id;
                self::linkChildToParent($clonedChild, $cloned);
            }
            self::syncSubtree($ctx, $cloned);
        } else {
            self::syncSubtree($ctx, $cloned);
        }

        return $cloned;
    }

    private static function serializeDoctype(string $name, string $publicId, string $systemId): string
    {
        if ('' !== $publicId || '' !== $systemId) {
            return sprintf(
                '<!DOCTYPE %s PUBLIC "%s" "%s">',
                self::escapeName($name),
                self::escapeAttr($publicId),
                self::escapeAttr($systemId)
            );
        }

        return '<!DOCTYPE '.self::escapeName($name).'>';
    }

    private static function escapeAttr(string $value): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    private static function escapeName(string $name): string
    {
        return $name;
    }

    public static function requireReceiver(Variable $var, string $classLc, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if ($classLc !== strtolower($object->class->name)) {
            throw new \TypeError(sprintf('%s must be called on a %s instance', $label, self::classNameFromLc($classLc)));
        }

        return $object;
    }

    public static function isDocumentType(ObjectEntry $entry): bool
    {
        return self::CLASS_DOCUMENT_TYPE === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_TYPE_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function typeLabel(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_FLOAT => 'float',
            default => 'mixed',
        };
    }

    private static function classNameFromLc(string $lc): string
    {
        return match ($lc) {
            self::CLASS_IMPLEMENTATION => 'DOMImplementation',
            self::CLASS_DOCUMENT => 'DOMDocument',
            self::CLASS_DOCUMENT_TYPE => 'DOMDocumentType',
            self::CLASS_ELEMENT => 'DOMElement',
            self::CLASS_DOCUMENT_FRAGMENT => 'DOMDocumentFragment',
            default => $lc,
        };
    }
}
