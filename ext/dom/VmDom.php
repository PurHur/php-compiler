<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\libxml\VmLibxml;
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
    private static ?ClassEntry $implementationClassEntry = null;

    /** @var array<int, ObjectEntry> */
    private static array $implementationSingletons = [];
    public const CLASS_IMPLEMENTATION = 'domimplementation';

    public const CLASS_DOCUMENT = 'domdocument';

    public const CLASS_DOCUMENT_TYPE = 'domdocumenttype';

    public const CLASS_ELEMENT = 'domelement';

    public const CLASS_TEXT = 'domtext';

    public const CLASS_ATTR = 'domattr';

    public const CLASS_DOCUMENT_FRAGMENT = 'domdocumentfragment';

    public const CLASS_ENTITY_REFERENCE = 'domentityreference';

    public const CLASS_NODE = 'domnode';

    public const CLASS_NODE_LIST = 'domnodelist';

    public const PROP_FORMAT_OUTPUT = 'formatOutput';

    public const PROP_IMPLEMENTATION = 'implementation';

    public const PROP_VALIDATE_ON_PARSE = 'validateOnParse';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public const PROP_ENCODING = 'encoding';

    public const PROP_XML_VERSION = 'xmlVersion';

    public const PROP_XML_STANDALONE = 'xmlStandalone';

    public const PROP_DOCUMENT_URI = 'documentURI';

    public const PROP_NODE_NAME = 'nodeName';

    public const PROP_TAG_NAME = 'tagName';

    public const PROP_NODE_TYPE = 'nodeType';

    public const PROP_OWNER_DOCUMENT = 'ownerDocument';

    public const PROP_NODE_VALUE = 'nodeValue';

    public const PROP_TEXT_CONTENT = 'textContent';

    public const PROP_BASE_URI = 'baseURI';

    public const PROP_NAMESPACE_URI = 'namespaceURI';

    public const PROP_LOCAL_NAME = 'localName';

    public const PROP_PREFIX = 'prefix';

    public const PROP_PREVIOUS_SIBLING = 'previousSibling';

    public const PROP_FIRST_CHILD = 'firstChild';

    public const PROP_LAST_CHILD = 'lastChild';

    public const PROP_CHILD_NODES = 'childNodes';

    public const PROP_NEXT_SIBLING = 'nextSibling';

    public const PROP_PARENT_NODE = 'parentNode';

    public const PROP_LENGTH = 'length';

    public const PROP_NAME = 'name';

    public const PROP_VALUE = 'value';

    public const PROP_OWNER_ELEMENT = 'ownerElement';

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
        $node->properties[] = new ClassProperty(self::PROP_NODE_TYPE, null, $intProto);
        $node->properties[] = new ClassProperty(self::PROP_OWNER_DOCUMENT, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_NODE_VALUE, $nullProto, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_TEXT_CONTENT, $nullProto, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_FIRST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_LAST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_CHILD_NODES, null, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_PREVIOUS_SIBLING, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_NEXT_SIBLING, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_PARENT_NODE, $nullProto, $objProto);
        $node->methods['clonenode'] = new NodeCloneNode();
        $node->methodVisibility['clonenode'] = $pub;
        $node->methods['replacechild'] = new NodeReplaceChild();
        $node->methodVisibility['replacechild'] = $pub;
        $node->methods['insertbefore'] = new NodeInsertBefore();
        $node->methodVisibility['insertbefore'] = $pub;
        $node->methods['removechild'] = new NodeRemoveChild();
        $node->methodVisibility['removechild'] = $pub;
        $node->methods['issamenode'] = new NodeIsSameNode();
        $node->methodVisibility['issamenode'] = $pub;
        $node->methods['isequalnode'] = new NodeIsEqualNode();
        $node->methodVisibility['isequalnode'] = $pub;
        $node->methods['haschildnodes'] = new NodeHasChildNodes();
        $node->methodVisibility['haschildnodes'] = $pub;
        if (CompilerVersion::supportsDomNodeContains()) {
            $node->methods['contains'] = new NodeContains();
            $node->methodVisibility['contains'] = $pub;
        }
        if (CompilerVersion::supportsDomNodeGetRootNode()) {
            $node->methods['getrootnode'] = new NodeGetRootNode();
            $node->methodVisibility['getrootnode'] = $pub;
        }
        $node->methods['append'] = new NodeAppend();
        $node->methodVisibility['append'] = $pub;
        $node->methods['prepend'] = new NodePrepend();
        $node->methodVisibility['prepend'] = $pub;
        $node->methods['lookupprefix'] = new NodeLookupPrefix();
        $node->methodVisibility['lookupprefix'] = $pub;
        $node->methods['lookupnamespaceuri'] = new NodeLookupNamespaceURI();
        $node->methodVisibility['lookupnamespaceuri'] = $pub;
        $node->methods['getlineno'] = new NodeGetLineNo();
        $node->methodVisibility['getlineno'] = $pub;
        $node->methods['getnodepath'] = new NodeGetNodePath();
        $node->methodVisibility['getnodepath'] = $pub;
        $node->methods['hasattributes'] = new NodeHasAttributes();
        $node->methodVisibility['hasattributes'] = $pub;
        $node->methods['isdefaultnamespace'] = new NodeIsDefaultNamespace();
        $node->methodVisibility['isdefaultnamespace'] = $pub;
        $node->methods['issupported'] = new NodeIsSupported();
        $node->methodVisibility['issupported'] = $pub;
        $node->methods['comparedocumentposition'] = new NodeCompareDocumentPosition();
        $node->methodVisibility['comparedocumentposition'] = $pub;
        DomClassConstants::registerIntConstants($node, [
            'DOCUMENT_POSITION_DISCONNECTED' => DomConstants::DOCUMENT_POSITION_DISCONNECTED,
            'DOCUMENT_POSITION_PRECEDING' => DomConstants::DOCUMENT_POSITION_PRECEDING,
            'DOCUMENT_POSITION_FOLLOWING' => DomConstants::DOCUMENT_POSITION_FOLLOWING,
            'DOCUMENT_POSITION_CONTAINS' => DomConstants::DOCUMENT_POSITION_CONTAINS,
            'DOCUMENT_POSITION_CONTAINED_BY' => DomConstants::DOCUMENT_POSITION_CONTAINED_BY,
            'DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC' => DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC,
        ]);
        $ctx->classes[self::CLASS_NODE] = $node;

        $text = new ClassEntry('DOMText');
        $text->isInternal = true;
        $text->parentLc = self::CLASS_NODE;
        $text->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $ctx->classes[self::CLASS_TEXT] = $text;

        $entityRef = new ClassEntry('DOMEntityReference');
        $entityRef->isInternal = true;
        $entityRef->parentLc = self::CLASS_NODE;
        $entityRef->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $ctx->classes[self::CLASS_ENTITY_REFERENCE] = $entityRef;

        $attr = new ClassEntry('DOMAttr');
        $attr->isInternal = true;
        $attr->parentLc = self::CLASS_NODE;
        $attr->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_VALUE, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_OWNER_ELEMENT, $nullProto, $objProto);
        $ctx->classes[self::CLASS_ATTR] = $attr;

        $nodeList = new ClassEntry('DOMNodeList');
        $nodeList->isInternal = true;
        $nodeList->interfaces[] = 'countable';
        $nodeList->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        $nodeList->methods['item'] = new NodeListItem();
        $nodeList->methodVisibility['item'] = $pub;
        $nodeList->methods['count'] = new NodeListCount();
        $nodeList->methodVisibility['count'] = $pub;
        $ctx->classes[self::CLASS_NODE_LIST] = $nodeList;

        $impl = new ClassEntry('DOMImplementation');
        $impl->isInternal = true;
        $impl->methods['createdocument'] = new ImplementationCreateDocument();
        $impl->methodVisibility['createdocument'] = $pub;
        $impl->methods['createdocumenttype'] = new ImplementationCreateDocumentType();
        $impl->methodVisibility['createdocumenttype'] = $pub;
        $impl->methods['getfeature'] = new ImplementationGetFeature();
        $impl->methodVisibility['getfeature'] = $pub;
        $impl->methods['hasfeature'] = new ImplementationHasFeature();
        $impl->methodVisibility['hasfeature'] = $pub;
        self::$implementationClassEntry = $impl;
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
        $document->methods['loadhtml'] = new DocumentLoadHTML();
        $document->methodVisibility['loadhtml'] = $pub;
        $document->methodNames['loadhtml'] = 'loadHTML';
        $document->methods['createelement'] = new DocumentCreateElement();
        $document->methodVisibility['createelement'] = $pub;
        $document->methods['createelementns'] = new DocumentCreateElementNS();
        $document->methodVisibility['createelementns'] = $pub;
        $document->methods['createattributens'] = new DocumentCreateAttributeNS();
        $document->methodVisibility['createattributens'] = $pub;
        $document->methods['createdocumentfragment'] = new DocumentCreateDocumentFragment();
        $document->methodVisibility['createdocumentfragment'] = $pub;
        $document->methods['createentityreference'] = new DocumentCreateEntityReference();
        $document->methodVisibility['createentityreference'] = $pub;
        $document->methodNames['createentityreference'] = 'createEntityReference';
        $document->methods['appendchild'] = new DocumentAppendChild();
        $document->methodVisibility['appendchild'] = $pub;
        $document->methods['savexml'] = new DocumentSaveXML();
        $document->methodVisibility['savexml'] = $pub;
        $document->methods['savehtml'] = new DocumentSaveHTML();
        $document->methodVisibility['savehtml'] = $pub;
        $document->methodNames['savehtml'] = 'saveHTML';
        $document->methods['savehtmlfile'] = new DocumentSaveHTMLFile();
        $document->methodVisibility['savehtmlfile'] = $pub;
        $document->methodNames['savehtmlfile'] = 'saveHTMLFile';
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
        $element->methods['getattribute'] = new ElementGetAttribute();
        $element->methodVisibility['getattribute'] = $pub;
        $element->methods['getattributens'] = new ElementGetAttributeNS();
        $element->methodVisibility['getattributens'] = $pub;
        $element->methods['hasattribute'] = new ElementHasAttribute();
        $element->methodVisibility['hasattribute'] = $pub;
        $element->methods['hasattributens'] = new ElementHasAttributeNS();
        $element->methodVisibility['hasattributens'] = $pub;
        $element->methods['removeattribute'] = new ElementRemoveAttribute();
        $element->methodVisibility['removeattribute'] = $pub;
        $element->methods['setattribute'] = new ElementSetAttribute();
        $element->methodVisibility['setattribute'] = $pub;
        $element->methods['setattributens'] = new ElementSetAttributeNS();
        $element->methodVisibility['setattributens'] = $pub;
        $element->methods['removeattributens'] = new ElementRemoveAttributeNS();
        $element->methodVisibility['removeattributens'] = $pub;
        $element->methods['setidattribute'] = new ElementSetIdAttribute();
        $element->methodVisibility['setidattribute'] = $pub;
        $element->methodNames['setidattribute'] = 'setIdAttribute';
        $element->methods['setidattributens'] = new ElementSetIdAttributeNS();
        $element->methodVisibility['setidattributens'] = $pub;
        $element->methodNames['setidattributens'] = 'setIdAttributeNS';
        $element->methods['getelementsbytagname'] = new ElementGetElementsByTagName();
        $element->methodVisibility['getelementsbytagname'] = $pub;
        $ctx->classes[self::CLASS_ELEMENT] = $element;

        $fragment = new ClassEntry('DOMDocumentFragment');
        $fragment->isInternal = true;
        $fragment->parentLc = self::CLASS_NODE;
        $fragment->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $fragment->methods['appendchild'] = new FragmentAppendChild();
        $fragment->methodVisibility['appendchild'] = $pub;
        $fragment->methods['appendxml'] = new FragmentAppendXML();
        $fragment->methodVisibility['appendxml'] = $pub;
        $fragment->methodNames['appendxml'] = 'appendXML';
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

        if ('' !== $qualifiedName) {
            $rootVar = null !== $namespaceUri && '' !== $namespaceUri
                ? self::createElementNS($ctx, $namespaceUri, $qualifiedName, $entry)
                : self::createElement($ctx, $qualifiedName, $entry);
            $root = $rootVar->toObject();
            $state->childIds = [$root->id];
            $entry->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($root);
            self::linkChildToParent($root, $entry);
            self::propagateDocumentId($root, $entry->id);
            self::syncSubtree($ctx, $entry);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hasFeature(string $feature, string $version): bool
    {
        $feature = strtoupper($feature);
        $version = trim($version);

        if ('CORE' === $feature) {
            return '1.0' === $version;
        }
        if ('XML' === $feature) {
            return '1.0' === $version || '2.0' === $version;
        }

        return false;
    }

    public static function implementationSingleton(): ObjectEntry
    {
        if (null === self::$implementationClassEntry) {
            throw new \LogicException('DOMImplementation is not registered in this compiler build');
        }
        $key = spl_object_id(self::$implementationClassEntry);
        if (!isset(self::$implementationSingletons[$key])) {
            $entry = new ObjectEntry(self::$implementationClassEntry);
            $entry->constructed = true;
            self::$implementationSingletons[$key] = $entry;
        }

        return self::$implementationSingletons[$key];
    }

    public static function isDefaultNamespace(ObjectEntry $node, string $namespaceUri): bool
    {
        $defaultNs = self::lookupNamespaceURI($node, null);
        if (null === $defaultNs) {
            return false;
        }

        return $defaultNs === $namespaceUri;
    }

    public static function ensureDocument(ObjectEntry $document): DomNodeState
    {
        if (!DomRegistry::has($document)) {
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
            $state->nodeName = '#document';
            DomRegistry::attach($document, $state);
            self::ensureDomDocumentBoolProperty($document, self::PROP_FORMAT_OUTPUT, false);
            self::initNodePropertySlots($document);
        }

        return DomRegistry::state($document);
    }

    public static function ensureDocumentFragment(ObjectEntry $fragment): DomNodeState
    {
        if (!DomRegistry::has($fragment)) {
            if (self::CLASS_DOCUMENT_FRAGMENT !== strtolower($fragment->class->name)) {
                throw new \LogicException('ensureDocumentFragment() expects a DOMDocumentFragment in this compiler build');
            }
            if ($fragment->hasProperty(self::PROP_NODE_NAME)) {
                $fragment->getProperty(self::PROP_NODE_NAME)->string('#document-fragment');
            }
            self::initNodePropertySlots($fragment);
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_FRAG_NODE;
            $state->nodeName = '#document-fragment';
            DomRegistry::attach($fragment, $state);
        }

        return DomRegistry::state($fragment);
    }

    public static function createElement(Context $ctx, string $name, ?ObjectEntry $ownerDocument = null): Variable
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
        $state->localName = $name;
        $state->prefix = null;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createEntityReference(
        Context $ctx,
        string $name,
        ?ObjectEntry $ownerDocument = null
    ): Variable {
        if ('' === $name || !preg_match('/^[A-Za-z_:][\w:.-]*$/', $name)) {
            throw new \DOMException('Invalid Character Error');
        }

        $class = $ctx->classes[self::CLASS_ENTITY_REFERENCE] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMEntityReference is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ENTITY_REF_NODE;
        $state->nodeName = $name;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createElementNS(
        Context $ctx,
        ?string $namespace,
        string $qualifiedName,
        ?ObjectEntry $ownerDocument = null,
        string $value = ''
    ): Variable {
        $class = $ctx->classes[self::CLASS_ELEMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMElement is not registered in this compiler build');
        }

        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $qualifiedName;
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
        $state->namespaceUri = $namespace;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);
        if ('' !== $value) {
            self::writeTextContent($ctx, $entry, $value);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createAttributeNS(
        Context $ctx,
        ?string $namespace,
        string $qualifiedName,
        ?ObjectEntry $ownerDocument = null
    ): Variable {
        $class = $ctx->classes[self::CLASS_ATTR] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMAttr is not registered in this compiler build');
        }

        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_VALUE)->string('');
        $entry->getProperty(self::PROP_OWNER_ELEMENT)->null();
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ATTRIBUTE_NODE;
        $state->nodeName = $qualifiedName;
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
        $state->namespaceUri = $namespace;
        $state->textContent = '';
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function getAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = '' !== $prefix ? (self::lookupNamespaceURI($element, $prefix) ?? '') : '';
            if ($attrNs === $wantNs) {
                return $value;
            }
        }

        return '';
    }

    public static function hasAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        return self::hasAttributeNSExact($element, $namespace, $localName);
    }

    private static function hasAttributeNSExact(ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = '' !== $prefix ? (self::lookupNamespaceURI($element, $prefix) ?? '') : '';

            return $attrNs === $wantNs;
        }

        return false;
    }

    public static function setAttributeNS(
        ObjectEntry $element,
        ?string $namespace,
        string $qualifiedName,
        string $value
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        $state->attributes[$qualifiedName] = $value;
        if (self::isXmlnsAttributeName($qualifiedName)) {
            $state->namespaceDeclarations = self::extractNamespaceDeclarations($state->attributes);
        }
        if (null !== $state->idAttributeName && $qualifiedName === $state->idAttributeName) {
            self::syncElementIdRegistration($element);
        }
    }

    public static function removeAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        $removedQName = null;
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = '' !== $prefix ? (self::lookupNamespaceURI($element, $prefix) ?? '') : '';
            if ($attrNs === $wantNs) {
                $removedQName = $qName;
                break;
            }
        }
        if (null === $removedQName) {
            return false;
        }
        unset($state->attributes[$removedQName]);
        if (null !== $state->idAttributeName && $removedQName === $state->idAttributeName) {
            $document = self::ownerDocumentEntry($element);
            if (null !== $document) {
                self::unregisterElementId($document, $element);
            }
            $state->idAttributeName = null;
        }

        return true;
    }

    /** DOMElement::setIdAttribute() — manual ID map for getElementById() (php-src ext/dom/node.c; #14493). */
    public static function setIdAttribute(ObjectEntry $element, string $name, bool $isId): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if (!\array_key_exists($name, $state->attributes)) {
            throw new \DOMException('Not Found Error', 8);
        }
        self::applyIdAttributeRegistration($element, $name, $isId);
    }

    /** DOMElement::setIdAttributeNS() — namespaced ID map (php-src ext/dom/element.c; #15300). */
    public static function setIdAttributeNS(
        ObjectEntry $element,
        ?string $namespace,
        string $localName,
        bool $isId
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $qName = self::findAttributeQNameByNsAndLocal($element, $namespace, $localName);
        if (null === $qName) {
            throw new \DOMException('Not Found Error', 8);
        }
        self::applyIdAttributeRegistration($element, $qName, $isId);
    }

    private static function applyIdAttributeRegistration(ObjectEntry $element, string $qName, bool $isId): void
    {
        $state = DomRegistry::state($element);
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            throw new \DOMException('Not Found Error', 8);
        }
        self::unregisterElementId($document, $element);
        if ($isId) {
            $state->idAttributeName = $qName;
            self::registerElementId($document, $element);
        } else {
            $state->idAttributeName = null;
        }
    }

    private static function findAttributeQNameByNsAndLocal(
        ObjectEntry $element,
        ?string $namespace,
        string $localName
    ): ?string {
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = '' !== $prefix ? (self::lookupNamespaceURI($element, $prefix) ?? '') : '';
            if ($attrNs === $wantNs) {
                return $qName;
            }
        }

        return null;
    }

    private static function registerElementId(ObjectEntry $document, ObjectEntry $element): void
    {
        $nodeState = DomRegistry::state($element);
        $idAttr = $nodeState->idAttributeName;
        if (null === $idAttr) {
            return;
        }
        $value = $nodeState->attributes[$idAttr] ?? null;
        if (null === $value || '' === $value) {
            return;
        }
        DomRegistry::state($document)->elementIds[$value] = $element->id;
    }

    private static function unregisterElementId(ObjectEntry $document, ObjectEntry $element): void
    {
        $nodeState = DomRegistry::state($element);
        $idAttr = $nodeState->idAttributeName;
        if (null === $idAttr) {
            return;
        }
        $value = $nodeState->attributes[$idAttr] ?? null;
        if (null === $value || '' === $value) {
            return;
        }
        $docState = DomRegistry::state($document);
        if (($docState->elementIds[$value] ?? null) === $element->id) {
            unset($docState->elementIds[$value]);
        }
    }

    private static function syncElementIdRegistration(ObjectEntry $element): void
    {
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            return;
        }
        self::unregisterElementId($document, $element);
        self::registerElementId($document, $element);
    }

    public static function lookupPrefix(ObjectEntry $node, ?string $namespace): ?string
    {
        if (null === $namespace || '' === $namespace) {
            return null;
        }
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            foreach ($state->namespaceDeclarations as $prefix => $uri) {
                if ($uri === $namespace) {
                    return '' === $prefix ? null : $prefix;
                }
            }
            $parentId = $state->parentId;
            if (null === $parentId) {
                break;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    public static function lookupNamespaceURI(ObjectEntry $node, ?string $prefix): ?string
    {
        $wantPrefix = $prefix ?? '';
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            if (isset($state->namespaceDeclarations[$wantPrefix])) {
                return $state->namespaceDeclarations[$wantPrefix];
            }
            $parentId = $state->parentId;
            if (null === $parentId) {
                break;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    public static function readBaseUri(ObjectEntry $node): string
    {
        $doc = self::ownerDocumentEntry($node);
        if (null === $doc) {
            return '';
        }
        $docState = DomRegistry::state($doc);
        if (null !== $docState->documentUri && '' !== $docState->documentUri) {
            return $docState->documentUri;
        }

        return '';
    }

    public static function readNamespaceUri(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType
            || DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType
        ) {
            return $state->namespaceUri;
        }

        return null;
    }

    public static function readLocalName(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);

        return $state->localName ?? $state->nodeName;
    }

    public static function readPrefix(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }

        return DomRegistry::state($node)->prefix ?? '';
    }

    public static function getLineNo(ObjectEntry $node): int
    {
        if (!DomRegistry::has($node)) {
            return 0;
        }

        return DomRegistry::state($node)->lineNo;
    }

    public static function getNodePath(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_FRAG_NODE === $state->nodeType) {
            return null;
        }
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return '/';
        }

        $segments = [];
        $current = $node;
        while (null !== $current) {
            $currentState = DomRegistry::state($current);
            if (DomConstants::XML_DOCUMENT_NODE === $currentState->nodeType) {
                break;
            }
            $segment = self::nodePathSegment($current);
            if (null !== $segment && '' !== $segment) {
                array_unshift($segments, $segment);
            }
            if (null === $currentState->parentId) {
                break;
            }
            $current = DomRegistry::entry($currentState->parentId);
            if (null === $current) {
                break;
            }
        }

        if ([] === $segments) {
            return '/';
        }

        return '/'.implode('/', $segments);
    }

    private static function nodePathSegment(ObjectEntry $node): ?string
    {
        $state = DomRegistry::state($node);
        if (DomConstants::XML_TEXT_NODE === $state->nodeType) {
            return 'text()';
        }
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType) {
            $name = $state->nodeName;
            $index = self::elementPathIndexAmongSiblings($node);
            if (null !== $index) {
                return $name.'['.$index.']';
            }

            return $name;
        }

        return $state->nodeName;
    }

    /** 1-based index when multiple element siblings share nodeName (php-src dom_node_get_node_path; #15125). */
    private static function elementPathIndexAmongSiblings(ObjectEntry $node): ?int
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            return null;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            return null;
        }
        $parentState = DomRegistry::state($parent);
        $nodeName = $state->nodeName;
        $sameNameCount = 0;
        $index = 0;
        foreach ($parentState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $childState = DomRegistry::state($child);
            if (DomConstants::XML_ELEMENT_NODE !== $childState->nodeType) {
                continue;
            }
            if ($childState->nodeName !== $nodeName) {
                continue;
            }
            ++$sameNameCount;
            if ($child->id === $node->id) {
                $index = $sameNameCount;
            }
        }
        if ($sameNameCount <= 1) {
            return null;
        }

        return $index;
    }

    public static function getRootNode(ObjectEntry $node): ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return $node;
        }
        $current = $node;
        while (true) {
            $state = DomRegistry::state($current);
            if (null === $state->parentId) {
                return $current;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent) {
                return $current;
            }
            $current = $parent;
        }
    }

    /**
     * @return array{0: string, 1: string} prefix, localName
     */
    private static function splitQualifiedName(string $qualifiedName): array
    {
        $pos = strpos($qualifiedName, ':');
        if (false === $pos) {
            return ['', $qualifiedName];
        }

        return [substr($qualifiedName, 0, $pos), substr($qualifiedName, $pos + 1)];
    }

    private static function isXmlnsAttributeName(string $name): bool
    {
        return 'xmlns' === $name || str_starts_with($name, 'xmlns:');
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    private static function extractNamespaceDeclarations(array $attributes): array
    {
        $declarations = [];
        foreach ($attributes as $name => $value) {
            if ('xmlns' === $name) {
                $declarations[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $declarations[substr($name, 6)] = $value;
            }
        }

        return $declarations;
    }

    private static function resolveElementNamespaceUri(ObjectEntry $element): void
    {
        if (!self::isElement($element)) {
            return;
        }
        $state = DomRegistry::state($element);
        $prefix = $state->prefix ?? '';
        if ('' === $prefix) {
            $state->namespaceUri = self::lookupNamespaceURI($element, '');
        } else {
            $state->namespaceUri = self::lookupNamespaceURI($element, $prefix);
        }
    }

    public static function createTextNode(Context $ctx, string $data, ?ObjectEntry $ownerDocument = null): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_TEXT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMText is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#text');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_TEXT_NODE;
        $state->nodeName = '#text';
        $state->textContent = $data;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
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

    public static function appendXML(
        Context $ctx,
        ObjectEntry $fragment,
        string $data,
        ?\PHPCompiler\Frame $frame = null
    ): bool {
        self::ensureDocumentFragment($fragment);
        if ('' === $data) {
            return false;
        }
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return true;
        }
        $children = self::parseFragmentXmlChildren($ctx, $trimmed);
        if (null === $children) {
            VmXml::validateAndReport($ctx, $trimmed, $frame);

            return false;
        }
        foreach ($children as $child) {
            self::appendChild($ctx, $fragment, $child);
        }

        return true;
    }

    public static function loadXML(Context $ctx, ObjectEntry $document, string $xml, ?\PHPCompiler\Frame $frame = null): bool
    {
        self::ensureDocument($document);

        $trimmed = trim($xml);
        $decl = self::parseXmlDeclaration($trimmed);
        $idAttrByElement = self::parseDoctypeIdAttributes($trimmed);
        [$elementXml, $elementOffset] = self::stripDoctypeWithOffset($trimmed);
        if (!VmXml::validateAndReport($ctx, $elementXml, $frame)) {
            return false;
        }
        $root = self::parseElementTree($ctx, $elementXml, $trimmed, $elementOffset);
        if (null === $root) {
            return false;
        }

        if (self::documentValidateOnParse($document)) {
            self::validateOnParseDtd($ctx, $trimmed, $root, $frame);
        }

        $state = DomRegistry::state($document);
        $state->childIds = [$root->id];
        $state->idAttrByElement = $idAttrByElement;
        $state->elementIds = [];
        $state->xmlVersion = $decl['version'];
        $state->encoding = $decl['encoding'];
        $state->xmlStandalone = $decl['standalone'];
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        self::linkChildToParent($root, $document);
        self::propagateDocumentId($root, $document->id);
        self::syncSubtree($ctx, $document);
        self::reindexDocumentIds($document, $root);
        $state->documentUri = self::defaultDocumentUri();

        return true;
    }

    /** Zend dom_document_documenturi_read default for in-memory documents (ext/dom/document.c; #14468). */
    private static function defaultDocumentUri(): string
    {
        $cwd = getcwd();
        if (false === $cwd || '' === $cwd) {
            return '/';
        }

        return str_ends_with($cwd, '/') ? $cwd : $cwd.'/';
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
        $idAttr = null;
        if (self::documentValidateOnParse($document)) {
            $idAttr = $docState->idAttrByElement[$nodeState->nodeName] ?? null;
        }
        if (null === $idAttr && null !== $nodeState->idAttributeName) {
            $idAttr = $nodeState->idAttributeName;
        }
        if (null === $idAttr && $docState->isHtmlDocument && isset($nodeState->attributes['id'])) {
            $idAttr = 'id';
        }
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
     * @return array{version: string, encoding: ?string, standalone: bool}
     */
    private static function parseXmlDeclaration(string $xml): array
    {
        $version = '1.0';
        $encoding = null;
        $standalone = false;
        if (!preg_match('/^\s*<\?xml\s+([^?]*)\?>/s', $xml, $match)) {
            return [
                'version' => $version,
                'encoding' => $encoding,
                'standalone' => $standalone,
            ];
        }
        $attrs = $match[1];
        if (preg_match('/version\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $versionMatch)) {
            $version = $versionMatch[2];
        }
        if (preg_match('/encoding\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $encodingMatch)) {
            $encoding = $encodingMatch[2];
        }
        if (preg_match('/standalone\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $standaloneMatch)) {
            $standalone = 'yes' === strtolower($standaloneMatch[2]);
        }

        return [
            'version' => $version,
            'encoding' => $encoding,
            'standalone' => $standalone,
        ];
    }

    private static function serializeXmlDeclaration(DomNodeState $state): string
    {
        $decl = '<?xml version="'.self::escapeAttribute($state->xmlVersion).'"';
        if (null !== $state->encoding && '' !== $state->encoding) {
            $decl .= ' encoding="'.self::escapeAttribute($state->encoding).'"';
        }
        if ($state->xmlStandalone) {
            $decl .= ' standalone="yes"';
        }
        $decl .= '?>';

        return $decl;
    }

    private static function escapeAttribute(string $value): string
    {
        return str_replace(['&', '"', '<'], ['&amp;', '&quot;', '&lt;'], $value);
    }

    /**
     * DTD validation warnings when validateOnParse is true (php-src ext/dom/document.c; #14536).
     */
    private static function validateOnParseDtd(
        Context $ctx,
        string $xml,
        ObjectEntry $root,
        ?\PHPCompiler\Frame $frame
    ): void {
        $doctypeName = self::parseDoctypeName($xml);
        if (null === $doctypeName) {
            self::reportDomLibxmlError($ctx, 'Validation failed: no DTD found !', 522, 1, $frame);

            return;
        }

        $rootName = DomRegistry::state($root)->nodeName;
        if ($doctypeName !== $rootName) {
            self::reportDomLibxmlError(
                $ctx,
                "root and DTD name do not match '{$rootName}' and '{$doctypeName}'",
                531,
                self::approximateXmlColumn($xml, $rootName),
                $frame
            );
        }

        $declaredElements = self::parseDoctypeElementDeclarations($xml);
        foreach (self::collectElementNames($root) as $elementName) {
            if (!isset($declaredElements[$elementName])) {
                self::reportDomLibxmlError(
                    $ctx,
                    "No declaration for element {$elementName}",
                    534,
                    self::approximateXmlColumn($xml, $elementName),
                    $frame
                );
            }
        }
    }

    private static function reportDomLibxmlError(
        Context $ctx,
        string $message,
        int $code,
        int $column,
        ?\PHPCompiler\Frame $frame
    ): void {
        VmLibxml::handleError($ctx, [
            'level' => LibxmlConstants::LIBXML_ERR_ERROR,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => 1,
        ], $frame, null, 'DOMDocument::loadXML(): '.$message.' in Entity, line: 1');
    }

    private static function approximateXmlColumn(string $xml, string $needle): int
    {
        $pos = strpos($xml, $needle);
        if (false === $pos) {
            return 1;
        }

        return $pos + 1;
    }

    private static function parseDoctypeName(string $xml): ?string
    {
        if (!preg_match('/<!DOCTYPE\s+([A-Za-z_][\w:.-]*)/', $xml, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return array<string, true>
     */
    private static function parseDoctypeElementDeclarations(string $xml): array
    {
        $declared = [];
        if (!preg_match('/<!DOCTYPE\s+\S+\s*\[(.*)\]\s*>/s', $xml, $doctype)) {
            return $declared;
        }
        if (!preg_match_all('/<!ELEMENT\s+(\S+)\s+/', $doctype[1], $matches)) {
            return $declared;
        }
        foreach ($matches[1] as $name) {
            $declared[$name] = true;
        }

        return $declared;
    }

    /**
     * @return list<string>
     */
    private static function collectElementNames(ObjectEntry $root): array
    {
        /** @var array<string, true> $names */
        $names = [];
        self::collectElementNamesRecursive($root, $names);

        return array_keys($names);
    }

    /**
     * @param array<string, true> $names
     */
    private static function collectElementNamesRecursive(ObjectEntry $node, array &$names): void
    {
        if (!self::isElement($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        $names[$state->nodeName] = true;
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementNamesRecursive($child, $names);
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
        return self::stripDoctypeWithOffset($xml)[0];
    }

    /**
     * @return array{0: string, 1: int} element XML and byte offset in $xml for line numbers (#15290)
     */
    private static function stripDoctypeWithOffset(string $xml): array
    {
        $offset = 0;
        if (preg_match('/^\s*<\?xml[^?]*\?>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        if (preg_match('/^\s*<!DOCTYPE\s+\S+\s*\[[^\]]*\]\s*>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        if (preg_match('/^\s*<!DOCTYPE[^>]*>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        $leading = \strlen($xml) - \strlen(ltrim($xml));
        $offset += $leading;
        $xml = ltrim($xml);

        return [rtrim($xml), $offset];
    }

    private static function lineNoAtOffset(string $sourceXml, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($sourceXml, 0, $offset), "\n") + 1;
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

        if (!self::isElement($child) && !self::isEntityReference($child) && !self::isTextNode($child)) {
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
            $parentState->childIds = [$child->id];
            $parentState->documentElementName = DomRegistry::state($child)->nodeName;
            $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);
            self::linkChildToParent($child, $parent);
            self::propagateDocumentId($child, $parent->id);
            self::syncSubtree($ctx, $parent);

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

    public static function replaceChild(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $oldChild
    ): ObjectEntry {
        self::assertMutationParent($parent);
        if (self::isDocumentFragment($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        if (!self::isElement($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertChildOfParent($parent, $oldChild, 'DOMNode::replaceChild()');
        self::assertSameDocument($parent, $newChild);
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        $index = self::childIndex($parentState->childIds, $oldChild->id);
        if (null === $index) {
            throw new \DOMException('Not found error');
        }
        $parentState->childIds[$index] = $newChild->id;
        self::linkChildToParent($oldChild, null);
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent)) {
            $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
            $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::syncSubtree($ctx, $parent);

        return $oldChild;
    }

    public static function insertBefore(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ?ObjectEntry $refChild
    ): ObjectEntry {
        self::assertMutationParent($parent);
        if (self::isDocumentFragment($newChild)) {
            return self::insertFragmentChildrenBefore($ctx, $parent, $newChild, $refChild);
        }
        if (!self::isElement($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $newChild);
        if (null !== $refChild) {
            self::assertChildOfParent($parent, $refChild, 'DOMNode::insertBefore()');
        }
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        if (null === $refChild) {
            $parentState->childIds[] = $newChild->id;
        } else {
            $index = self::childIndex($parentState->childIds, $refChild->id);
            if (null === $index) {
                throw new \DOMException('Not found error');
            }
            \array_splice($parentState->childIds, $index, 0, [$newChild->id]);
        }
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent)) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            }
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::syncSubtree($ctx, $parent);

        return $newChild;
    }

    public static function removeChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        self::assertMutationParent($parent);
        self::assertChildOfParent($parent, $child, 'DOMNode::removeChild()');
        $parentState = DomRegistry::state($parent);
        $parentState->childIds = \array_values(\array_filter(
            $parentState->childIds,
            static fn (int $id): bool => $id !== $child->id
        ));
        self::linkChildToParent($child, null);
        if (self::isDocument($parent)) {
            $docEl = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $docEl->type && $docEl->toObject()->id === $child->id) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->null();
                $parentState->documentElementName = null;
            }
        }
        self::syncSubtree($ctx, $parent);

        return $child;
    }

    private static function appendFragmentChildren(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $fragment
    ): ObjectEntry {
        return self::insertFragmentChildrenBefore($ctx, $parent, $fragment, null);
    }

    private static function insertFragmentChildrenBefore(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $fragment,
        ?ObjectEntry $refChild
    ): ObjectEntry {
        if (!self::isDocumentFragment($fragment)) {
            throw new \LogicException('insertFragmentChildrenBefore() expects a DOMDocumentFragment');
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
            if (null === $refChild) {
                self::appendChild($ctx, $parent, $child);
            } else {
                self::insertBeforeLiveStandard($ctx, $parent, $child, $refChild);
            }
        }
        self::syncSubtree($ctx, $fragment);
        self::syncSubtree($ctx, $parent);

        return $fragment;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function appendLiveStandardNodes(Context $ctx, ObjectEntry $parent, array $args): void
    {
        self::assertMutationParent($parent);
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::append()');
            self::appendLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function prependLiveStandardNodes(Context $ctx, ObjectEntry $parent, array $args): void
    {
        self::assertMutationParent($parent);
        for ($i = \count($args) - 1; $i >= 0; --$i) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $args[$i], 'DOMNode::prepend()');
            self::prependLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    public static function appendLiveStandardChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::isDocumentFragment($child)) {
            self::appendFragmentChildren($ctx, $parent, $child);

            return;
        }
        if (!self::isElement($child) && !self::isTextNode($child) && !self::isEntityReference($child)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $child);
        self::detachNodeIfAttached($ctx, $child);

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type && self::isElement($child)) {
                $parentState->childIds = [$child->id];
                $parentState->documentElementName = DomRegistry::state($child)->nodeName;
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);
                self::linkChildToParent($child, $parent);
                self::propagateDocumentId($child, $parent->id);

                return;
            }
            $parentState->childIds[] = $child->id;
            self::linkChildToParent($child, $parent);
            if (self::isElement($child)) {
                self::propagateDocumentId($child, $parent->id);
            }

            return;
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $parentState->nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState->childIds[] = $child->id;
        self::linkChildToParent($child, $parent);
    }

    public static function prependLiveStandardChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::isDocumentFragment($child)) {
            $fragState = DomRegistry::state($child);
            $childIds = $fragState->childIds;
            $fragState->childIds = [];
            for ($i = \count($childIds) - 1; $i >= 0; --$i) {
                $fragChild = DomRegistry::entry($childIds[$i]);
                if (null === $fragChild) {
                    continue;
                }
                self::linkChildToParent($fragChild, null);
                self::prependLiveStandardChild($ctx, $parent, $fragChild);
            }
            self::syncSubtree($ctx, $child);

            return;
        }

        $firstChild = null;
        if (DomRegistry::has($parent) && [] !== DomRegistry::state($parent)->childIds) {
            $firstChild = DomRegistry::entry(DomRegistry::state($parent)->childIds[0]);
        }
        self::insertBeforeLiveStandard($ctx, $parent, $child, $firstChild);
    }

    private static function insertBeforeLiveStandard(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ?ObjectEntry $refChild
    ): void {
        self::assertMutationParent($parent);
        if (!self::isElement($newChild) && !self::isTextNode($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $newChild);
        if (null !== $refChild) {
            self::assertChildOfParent($parent, $refChild, 'DOMNode::insertBefore()');
        }
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        if (null === $refChild) {
            if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
                $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
                if (Variable::TYPE_NULL === $existing->type && self::isElement($newChild)) {
                    $parentState->childIds = [$newChild->id];
                    $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
                    $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                    self::linkChildToParent($newChild, $parent);
                    self::propagateDocumentId($newChild, $parent->id);

                    return;
                }
            }
            $parentState->childIds[] = $newChild->id;
        } else {
            $index = self::childIndex($parentState->childIds, $refChild->id);
            if (null === $index) {
                throw new \DOMException('Not found error');
            }
            \array_splice($parentState->childIds, $index, 0, [$newChild->id]);
        }
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent) && self::isElement($newChild)) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            } elseif (null !== $refChild && Variable::TYPE_OBJECT === $existing->type) {
                $docEl = $existing->toObject();
                if ($docEl->id === $refChild->id) {
                    $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                    $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
                }
            }
            self::propagateDocumentId($newChild, $parent->id);
        }
    }

    private static function resolveLiveStandardAppendArg(
        Context $ctx,
        ObjectEntry $parent,
        Variable $arg,
        string $label
    ): ObjectEntry {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $owner = self::ownerDocumentEntry($parent);
            if (null === $owner && self::isDocument($parent)) {
                $owner = $parent;
            }

            return self::createTextNode($ctx, $arg->toString(), $owner);
        }
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s expects argument to be of type DOMNode|string, %s given',
                $label,
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (!self::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s expects argument to be of type DOMNode|string, %s given',
                $label,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function saveXML(ObjectEntry $document, ?ObjectEntry $node = null): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveXML() called on non-document node in this compiler build');
        }

        $formatOutput = self::documentFormatOutput($document);

        if (null !== $node) {
            if (!self::isDomNode($node)) {
                throw new \TypeError('DOMDocument::saveXML(): Argument #1 ($node) must be of type DOMNode');
            }

            return self::serializeNode($node, 0, $formatOutput);
        }

        $lines = [self::serializeXmlDeclaration($state)];

        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? ''
            );
        }

        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $lines[] = self::serializeElement($rootVar->toObject(), 0, $formatOutput);
        } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
            $lines[] = '<'.self::escapeName($state->documentElementName).'/>';
        }

        return implode("\n", $lines)."\n";
    }

    private static function documentFormatOutput(ObjectEntry $document): bool
    {
        return self::ensureDomDocumentBoolProperty($document, self::PROP_FORMAT_OUTPUT, false);
    }

    private static function ensureDomDocumentBoolProperty(
        ObjectEntry $document,
        string $propName,
        bool $default
    ): bool {
        if (!$document->hasProperty($propName)) {
            return $default;
        }
        $slot = $document->getProperty($propName);
        $prop = $slot->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $prop->type) {
            $slot->bool($default);

            return $default;
        }
        try {
            return $prop->toBool();
        } catch (\Error) {
            $slot->bool($default);

            return $default;
        }
    }

    public static function loadHTML(Context $ctx, ObjectEntry $document, string $html, int $options = 0): bool
    {
        self::ensureDocument($document);

        $source = self::normalizeHtmlLoadSource($html, $options);
        $root = self::parseHtmlElementTree($ctx, $source, $document);
        if (null === $root) {
            return false;
        }

        $state = DomRegistry::state($document);
        $state->isHtmlDocument = true;
        $state->childIds = [$root->id];
        $state->idAttrByElement = [];
        $state->elementIds = [];
        $state->xmlVersion = '1.0';
        $state->encoding = null;
        $state->xmlStandalone = false;
        $state->doctypeName = 'html';
        $state->doctypePublicId = '-//W3C//DTD HTML 4.0 Transitional//EN';
        $state->doctypeSystemId = 'http://www.w3.org/TR/REC-html40/loose.dtd';
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        self::linkChildToParent($root, $document);
        self::propagateDocumentId($root, $document->id);
        self::syncSubtree($ctx, $document);
        self::reindexDocumentIds($document, $root);
        $state->documentUri = self::defaultDocumentUri();

        return true;
    }

    public static function saveHTML(ObjectEntry $document, ?ObjectEntry $node = null): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveHTML() called on non-document node in this compiler build');
        }

        if (null !== $node) {
            if (!self::isDomNode($node)) {
                throw new \TypeError('DOMDocument::saveHTML(): Argument #1 ($node) must be of type ?DOMNode');
            }

            return self::serializeHtmlNode($node);
        }

        $lines = [self::serializeHtmlDoctype()];

        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $lines[] = self::serializeHtmlNode($rootVar->toObject());
        } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
            $lines[] = '<'.self::escapeName($state->documentElementName).'/>';
        }

        return implode('', $lines)."\n";
    }

    public static function saveHTMLFile(ObjectEntry $document, string $filename): int
    {
        $html = self::saveHTML($document);
        $written = file_put_contents($filename, $html);
        if (false === $written) {
            return 0;
        }

        return $written;
    }

    private static function normalizeHtmlLoadSource(string $html, int $options): string
    {
        $trimmed = trim($html);
        if (0 !== ($options & \PHPCompiler\ext\libxml\LibxmlConstants::LIBXML_HTML_NOIMPLIED)) {
            return $trimmed;
        }
        if (preg_match('/^\s*<(?:!DOCTYPE|html\b)/i', $trimmed)) {
            return preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $trimmed) ?? $trimmed;
        }

        return '<html><body>'.$trimmed.'</body></html>';
    }

    private static function parseHtmlElementTree(Context $ctx, string $html, ObjectEntry $ownerDocument): ?ObjectEntry
    {
        $trimmed = trim($html);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            return self::createHtmlElementFromTag($ctx, $selfClose[1], $selfClose[2] ?? '', '', $ownerDocument);
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/is', $trimmed, $matches)) {
            return null;
        }

        $entry = self::createHtmlElementFromTag($ctx, $matches[1], $matches[2] ?? '', $matches[3], $ownerDocument);
        self::syncSubtree($ctx, $entry);

        return $entry;
    }

    private static function createHtmlElementFromTag(
        Context $ctx,
        string $tagName,
        string $attrPart,
        string $inner,
        ObjectEntry $ownerDocument,
    ): ObjectEntry {
        $localName = strtolower($tagName);
        $entry = self::createElement($ctx, $localName)->toObject();
        $state = DomRegistry::state($entry);
        $state->attributes = self::parseAttributes($attrPart);
        self::applyQualifiedElementNames($state, $localName);
        $state->namespaceDeclarations = self::extractNamespaceDeclarations($state->attributes);
        self::appendHtmlChildren($ctx, $entry, $inner, $ownerDocument);

        return $entry;
    }

    private static function appendHtmlChildren(
        Context $ctx,
        ObjectEntry $parent,
        string $inner,
        ObjectEntry $ownerDocument,
    ): void {
        $state = DomRegistry::state($parent);
        $pos = 0;
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
                $text = false === $next ? substr($inner, $pos) : substr($inner, $pos, $next - $pos);
                if ('' !== $text) {
                    $textNode = self::createTextNode($ctx, $text, $ownerDocument);
                    $state->childIds[] = $textNode->id;
                    self::linkChildToParent($textNode, $parent);
                }
                $pos = false === $next ? $len : $next;

                continue;
            }
            $end = self::findHtmlElementEnd($inner, $pos);
            if (null === $end) {
                return;
            }
            $childHtml = substr($inner, $pos, $end - $pos);
            $child = self::parseHtmlElementTree($ctx, $childHtml, $ownerDocument);
            if (null === $child) {
                return;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $parent);
            self::resolveElementNamespaceUri($child);
            $pos = $end;
        }
    }

    /** @return null|int byte offset after one HTML element starting at $pos */
    private static function findHtmlElementEnd(string $content, int $pos): ?int
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/is', $content, $selfClose, 0, $pos)) {
            return $pos + \strlen($selfClose[0]);
        }
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/is', $content, $open, 0, $pos)) {
            return null;
        }

        $tag = strtolower($open[1]);
        /** @var list<string> $stack */
        $stack = [$tag];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/is', $content, $close, 0, $scan)) {
                $name = strtolower($close[1]);
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
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/is', $content, $nestedSelf, 0, $scan)) {
                $scan += \strlen($nestedSelf[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/is', $content, $nestedOpen, 0, $scan)) {
                $stack[] = strtolower($nestedOpen[1]);
                $scan += \strlen($nestedOpen[0]);

                continue;
            }
            ++$scan;
        }

        return null;
    }

    private static function serializeHtmlDoctype(): string
    {
        return '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">'."\n";
    }

    private static function serializeHtmlNode(ObjectEntry $entry): string
    {
        if (self::isElement($entry)) {
            return self::serializeHtmlElement($entry);
        }
        if (self::isTextNode($entry)) {
            return DomRegistry::state($entry)->textContent ?? '';
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    private static function serializeHtmlElement(ObjectEntry $entry): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $attrPart = self::serializeAttributes($state);
        if ([] === $state->childIds) {
            return '<'.$name.$attrPart.'/>';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child);
            }
        }

        return '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';
    }

    private static function elementVariable(ObjectEntry $entry): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * @return null|list<ObjectEntry>
     */
    private static function parseFragmentXmlChildren(Context $ctx, string $xml): ?array
    {
        $children = [];
        $pos = 0;
        $len = \strlen($xml);
        while ($pos < $len) {
            if (preg_match('/\G\s+/s', $xml, $m, 0, $pos)) {
                $pos += \strlen($m[0]);

                continue;
            }
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $xml[$pos]) {
                $next = strpos($xml, '<', $pos);
                $text = false === $next ? substr($xml, $pos) : substr($xml, $pos, $next - $pos);
                $children[] = self::createTextNode($ctx, $text, null);
                $pos = false === $next ? $len : $next;

                continue;
            }
            $end = self::findElementEnd($xml, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($xml, $pos, $end - $pos);
            $child = self::parseElementTree($ctx, $childXml, $xml, $pos);
            if (null === $child) {
                return null;
            }
            $children[] = $child;
            $pos = $end;
        }

        return $children;
    }

    private static function parseElementTree(
        Context $ctx,
        string $elementXml,
        string $sourceXml,
        int $baseOffset
    ): ?ObjectEntry {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            $entry = self::createElement($ctx, $selfClose[1])->toObject();
            $state = DomRegistry::state($entry);
            $state->lineNo = self::lineNoAtOffset($sourceXml, $baseOffset);
            $state->attributes = self::parseAttributes($selfClose[2] ?? '');
            self::applyQualifiedElementNames($state, $selfClose[1]);
            $state->namespaceDeclarations = self::extractNamespaceDeclarations($state->attributes);

            return $entry;
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $entry = self::createElement($ctx, $matches[1])->toObject();
        $state = DomRegistry::state($entry);
        $state->lineNo = self::lineNoAtOffset($sourceXml, $baseOffset);
        $state->attributes = self::parseAttributes($matches[2] ?? '');
        self::applyQualifiedElementNames($state, $matches[1]);
        $state->namespaceDeclarations = self::extractNamespaceDeclarations($state->attributes);
        $openTag = '<'.$matches[1].($matches[2] ?? '').'>';
        $innerBase = $baseOffset + \strlen($openTag);
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
            $child = self::parseElementTree($ctx, $childXml, $sourceXml, $innerBase + $pos);
            if (null === $child) {
                return null;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $entry);
            self::resolveElementNamespaceUri($child);
            $pos = $end;
        }

        self::syncSubtree($ctx, $entry);

        return $entry;
    }

    private static function applyQualifiedElementNames(DomNodeState $state, string $qualifiedName): void
    {
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
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

    private static function serializeNode(ObjectEntry $entry, int $depth = 0, bool $format = false): string
    {
        if (self::isElement($entry)) {
            return self::serializeElement($entry, $depth, $format);
        }
        if (self::isTextNode($entry)) {
            $text = self::escapeText(DomRegistry::state($entry)->textContent ?? '');
            if (!$format || '' === $text) {
                return $text;
            }

            return str_repeat('  ', $depth).$text;
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    private static function serializeElement(ObjectEntry $entry, int $depth = 0, bool $format = false): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $attrPart = self::serializeAttributes($state);
        if ([] === $state->childIds) {
            $tag = '<'.$name.$attrPart.'/>';

            return $format ? str_repeat('  ', $depth).$tag : $tag;
        }
        if (!$format) {
            $parts = [];
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $parts[] = self::serializeNode($child);
                }
            }

            return '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';
        }

        $indent = str_repeat('  ', $depth);
        $lines = [$indent.'<'.$name.$attrPart.'>'];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $lines[] = self::serializeNode($child, $depth + 1, true);
            }
        }
        $lines[] = $indent.'</'.$name.'>';

        return implode("\n", $lines);
    }

    /** @return non-empty-string */
    private static function serializeAttributes(DomNodeState $state): string
    {
        if ([] === $state->attributes) {
            return '';
        }
        $parts = [];
        foreach ($state->attributes as $aname => $avalue) {
            $parts[] = self::escapeName($aname).'="'.self::escapeAttr($avalue).'"';
        }

        return ' '.implode(' ', $parts);
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

        return self::getElementsByTagNameFromNode($ctx, $rootVar->toObject(), $tagName);
    }

    public static function getElementsByTagNameFromNode(
        Context $ctx,
        ObjectEntry $node,
        string $tagName
    ): Variable {
        if (!self::isElement($node)) {
            throw new \DOMException('Not an element node');
        }

        return self::createNodeList($ctx, self::collectElementsByTagName($node, $tagName));
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
        if (!$entry->hasProperty(self::PROP_PREVIOUS_SIBLING)) {
            $entry->allocateProperty(self::PROP_PREVIOUS_SIBLING)->null();
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
        if (null !== $parent) {
            $parentState = DomRegistry::state($parent);
            if (self::isDocument($parent)) {
                $childState->documentId = $parent->id;
            } elseif (null !== $parentState->documentId) {
                $childState->documentId = $parentState->documentId;
            }
        }
    }

    private static function assertMutationParent(ObjectEntry $parent): void
    {
        if (!DomRegistry::has($parent)) {
            throw new \DOMException('Hierarchy request error');
        }
        $nodeType = DomRegistry::state($parent)->nodeType;
        if (DomConstants::XML_ELEMENT_NODE !== $nodeType
            && DomConstants::XML_DOCUMENT_NODE !== $nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }
    }

    private static function assertChildOfParent(ObjectEntry $parent, ObjectEntry $child, string $label): void
    {
        if (!DomRegistry::has($child)) {
            throw new \DOMException('Not found error');
        }
        $childState = DomRegistry::state($child);
        if ($childState->parentId !== $parent->id) {
            throw new \DOMException('Not found error');
        }
        if (!\in_array($child->id, DomRegistry::state($parent)->childIds, true)) {
            throw new \DOMException('Not found error');
        }
    }

    private static function assertSameDocument(ObjectEntry $parent, ObjectEntry $child): void
    {
        $parentDocId = self::resolveDocumentId($parent);
        $childDocId = self::resolveDocumentId($child);
        if (null !== $parentDocId && null !== $childDocId && $parentDocId !== $childDocId) {
            throw new \DOMException('Wrong Document Error');
        }
    }

    private static function resolveDocumentId(ObjectEntry $node): ?int
    {
        if (self::isDocument($node)) {
            return $node->id;
        }
        if (!DomRegistry::has($node)) {
            return null;
        }

        return DomRegistry::state($node)->documentId;
    }

    private static function detachNodeIfAttached(Context $ctx, ObjectEntry $node): void
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            return;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            self::linkChildToParent($node, null);

            return;
        }
        self::removeChild($ctx, $parent, $node);
    }

    /** @param list<int> $childIds */
    private static function childIndex(array $childIds, int $childId): ?int
    {
        $index = \array_search($childId, $childIds, true);

        return false === $index ? null : (int) $index;
    }

    private static function propagateDocumentId(ObjectEntry $node, int $documentId): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            $state->documentId = $documentId;
        }
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::propagateDocumentId($child, $documentId);
            }
        }
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
            $prevVar = $child->getProperty(self::PROP_PREVIOUS_SIBLING);
            $prevId = $state->childIds[$index - 1] ?? null;
            if (null !== $prevId) {
                $prev = DomRegistry::entry($prevId);
                if (null !== $prev) {
                    $prevVar->object($prev);
                } else {
                    $prevVar->null();
                }
            } else {
                $prevVar->null();
            }
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
            $name = self::readLocalName($node);
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

    public static function isDomNode(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry);
    }

    public static function isSameNode(ObjectEntry $node, ObjectEntry $other): bool
    {
        return $node->id === $other->id;
    }

    public static function isEqualNode(ObjectEntry $node, ObjectEntry $other): bool
    {
        if ($node->id === $other->id) {
            return true;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return false;
        }
        $stateA = DomRegistry::state($node);
        $stateB = DomRegistry::state($other);
        if ($stateA->nodeType !== $stateB->nodeType) {
            return false;
        }
        if ($stateA->nodeName !== $stateB->nodeName) {
            return false;
        }
        if (self::readNamespaceUri($node) !== self::readNamespaceUri($other)) {
            return false;
        }
        if (self::readLocalName($node) !== self::readLocalName($other)) {
            return false;
        }
        if (self::readPrefix($node) !== self::readPrefix($other)) {
            return false;
        }

        if (DomConstants::XML_ATTRIBUTE_NODE === $stateA->nodeType) {
            return self::readNodeValue($node) === self::readNodeValue($other);
        }
        if (DomConstants::XML_TEXT_NODE === $stateA->nodeType) {
            return ($stateA->textContent ?? '') === ($stateB->textContent ?? '');
        }
        if (DomConstants::XML_DOCUMENT_TYPE_NODE === $stateA->nodeType) {
            return ($stateA->publicId ?? '') === ($stateB->publicId ?? '')
                && ($stateA->systemId ?? '') === ($stateB->systemId ?? '');
        }
        if (self::isElement($node)) {
            if (!self::elementAttributesEqual($node, $other)) {
                return false;
            }
        }

        if (\count($stateA->childIds) !== \count($stateB->childIds)) {
            return false;
        }
        foreach ($stateA->childIds as $i => $childIdA) {
            $childIdB = $stateB->childIds[$i];
            $childA = DomRegistry::entry($childIdA);
            $childB = DomRegistry::entry($childIdB);
            if (null === $childA || null === $childB) {
                return false;
            }
            if (!self::isEqualNode($childA, $childB)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function normalizedElementAttributes(ObjectEntry $element): array
    {
        $state = DomRegistry::state($element);
        $attrs = $state->attributes;
        ksort($attrs);

        return $attrs;
    }

    private static function elementAttributesEqual(ObjectEntry $a, ObjectEntry $b): bool
    {
        return self::normalizedElementAttributes($a) === self::normalizedElementAttributes($b);
    }

    public static function hasChildNodes(ObjectEntry $node): bool
    {
        if (!DomRegistry::has($node)) {
            return false;
        }

        return [] !== DomRegistry::state($node)->childIds;
    }

    public static function hasAttributes(ObjectEntry $node): bool
    {
        if (!self::isElement($node)) {
            return false;
        }
        $state = DomRegistry::state($node);
        foreach ($state->attributes as $qName => $value) {
            if (!self::isXmlnsAttributeName($qName)) {
                return true;
            }
        }

        return false;
    }

    public static function compareDocumentPosition(ObjectEntry $node, ObjectEntry $other): int
    {
        if ($node->id === $other->id) {
            return 0;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return self::disconnectedDocumentPosition($node, $other);
        }

        $root1 = self::getTreeRoot($node);
        $root2 = self::getTreeRoot($other);
        if ($root1->id !== $root2->id) {
            return self::disconnectedDocumentPosition($node, $other);
        }

        if (self::contains($node, $other)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINS | DomConstants::DOCUMENT_POSITION_PRECEDING;
        }
        if (self::contains($other, $node)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINED_BY | DomConstants::DOCUMENT_POSITION_FOLLOWING;
        }

        $orderNode = self::documentOrderIndex($root1, $node);
        $orderOther = self::documentOrderIndex($root1, $other);
        if ($orderNode < 0 || $orderOther < 0) {
            return self::disconnectedDocumentPosition($node, $other);
        }
        if ($orderNode < $orderOther) {
            return DomConstants::DOCUMENT_POSITION_FOLLOWING;
        }

        return DomConstants::DOCUMENT_POSITION_PRECEDING;
    }

    private static function disconnectedDocumentPosition(ObjectEntry $node, ObjectEntry $other): int
    {
        $ordering = $node->id < $other->id
            ? DomConstants::DOCUMENT_POSITION_PRECEDING
            : DomConstants::DOCUMENT_POSITION_FOLLOWING;

        return DomConstants::DOCUMENT_POSITION_DISCONNECTED
            | DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC
            | $ordering;
    }

    private static function getTreeRoot(ObjectEntry $node): ObjectEntry
    {
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
                return $current;
            }
            if (null === $state->parentId) {
                return $current;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent) {
                return $current;
            }
            $current = $parent;
        }

        return $current;
    }

    private static function documentOrderIndex(ObjectEntry $root, ObjectEntry $target): int
    {
        $counter = 0;
        $found = -1;
        self::walkDocumentOrder($root, $target, $counter, $found);

        return $found;
    }

    private static function walkDocumentOrder(
        ObjectEntry $node,
        ObjectEntry $target,
        int &$counter,
        int &$found
    ): void {
        if ($node->id === $target->id) {
            $found = $counter;

            return;
        }
        ++$counter;
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::walkDocumentOrder($child, $target, $counter, $found);
                if ($found >= 0) {
                    return;
                }
            }
        }
    }

    public static function contains(ObjectEntry $node, ?ObjectEntry $other): bool
    {
        if (null === $other) {
            return false;
        }
        if ($node->id === $other->id) {
            return true;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return false;
        }
        $current = $other;
        while (null !== DomRegistry::state($current)->parentId) {
            $parentId = DomRegistry::state($current)->parentId;
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                return false;
            }
            if ($parent->id === $node->id) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    public static function ownerDocumentEntry(ObjectEntry $node): ?ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return null;
        }
        if (null === $state->documentId) {
            return null;
        }

        return DomRegistry::entry($state->documentId);
    }

    public static function readNodeValue(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return null;
        }
        if (DomConstants::XML_TEXT_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType) {
            $parts = [];
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null === $child) {
                    continue;
                }
                $childValue = self::readNodeValue($child);
                if (null !== $childValue && '' !== $childValue) {
                    $parts[] = $childValue;
                }
            }

            return implode('', $parts);
        }

        return null;
    }

    public static function writeNodeValue(Context $ctx, ObjectEntry $node, string $value): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            $state->textContent = $value;
            if ($node->hasProperty(self::PROP_VALUE)) {
                $node->getProperty(self::PROP_VALUE)->string($value);
            }
            if ($node->hasProperty(self::PROP_NODE_VALUE)) {
                $node->getProperty(self::PROP_NODE_VALUE)->string($value);
            }

            return;
        }
        if (DomConstants::XML_ELEMENT_NODE !== $state->nodeType) {
            return;
        }
        $ownerDoc = self::ownerDocumentEntry($node);
        $state->childIds = [];
        if ('' !== $value) {
            $text = self::createTextNode($ctx, $value, $ownerDoc);
            $state->childIds[] = $text->id;
            self::linkChildToParent($text, $node);
        }
        self::syncSubtree($ctx, $node);
    }

    public static function readTextContent(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_TEXT_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::readTextContent($child);
            }
        }

        return implode('', $parts);
    }

    public static function writeTextContent(Context $ctx, ObjectEntry $node, string $value): void
    {
        self::writeNodeValue($ctx, $node, $value);
    }

    public static function isElement(ObjectEntry $entry): bool
    {
        return self::CLASS_ELEMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_ELEMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isTextNode(ObjectEntry $entry): bool
    {
        return self::CLASS_TEXT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_TEXT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isEntityReference(ObjectEntry $entry): bool
    {
        return self::CLASS_ENTITY_REFERENCE === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_ENTITY_REF_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isAttr(ObjectEntry $entry): bool
    {
        return self::CLASS_ATTR === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_ATTRIBUTE_NODE === DomRegistry::state($entry)->nodeType;
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

    public static function isAppendChildCandidate(ObjectEntry $entry): bool
    {
        return self::isElement($entry)
            || self::isDocumentFragment($entry)
            || self::isTextNode($entry)
            || self::isEntityReference($entry);
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
        $clonedState = DomRegistry::state($cloned);
        $clonedState->documentId = $sourceState->documentId;
        if (self::isElement($source)) {
            $clonedState->attributes = $sourceState->attributes;
            $clonedState->namespaceDeclarations = $sourceState->namespaceDeclarations;
            $clonedState->localName = $sourceState->localName;
            $clonedState->prefix = $sourceState->prefix;
            $clonedState->namespaceUri = $sourceState->namespaceUri;
        }
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

    private static function escapeText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
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
