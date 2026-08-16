<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\VmDomValidationNative;
use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * XMLReader pull parser — PHP-in-PHP tokenizer (php-src ext/xmlreader/php_xmlreader.c; #6135).
 */
final class VmXmlReader
{
    public const CLASS_LC = 'xmlreader';

    public const PROP_ATTRIBUTE_COUNT = 'attributeCount';
    public const PROP_BASE_URI = 'baseURI';
    public const PROP_DEPTH = 'depth';
    public const PROP_HAS_ATTRIBUTES = 'hasAttributes';
    public const PROP_HAS_VALUE = 'hasValue';
    public const PROP_IS_DEFAULT = 'isDefault';
    public const PROP_IS_EMPTY_ELEMENT = 'isEmptyElement';
    public const PROP_LOCAL_NAME = 'localName';
    public const PROP_NAME = 'name';
    public const PROP_NAMESPACE_URI = 'namespaceURI';
    public const PROP_NODE_TYPE = 'nodeType';
    public const PROP_PREFIX = 'prefix';
    public const PROP_VALUE = 'value';
    public const PROP_XML_LANG = 'xmlLang';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;

        $entry = new ClassEntry('XMLReader');
        $entry->methods['open'] = new XmlReaderOpen();
        $entry->methodVisibility['open'] = $pubStatic;
        $entry->methodNames['open'] = 'open';
        $entry->methods['xml'] = new XmlReaderXML();
        $entry->methodVisibility['xml'] = $pubStatic;
        $entry->methodNames['xml'] = 'XML';
        // php-src stub: open()/XML() have no Reflection return type (@return bool|XMLReader
        // call-scope TODO). Do not advertise `static` here — that lied vs Zend (#28712).
        // AOT object-result typing for XML()/fromString is handled in JIT
        // (propagateXmlReaderFactoryResultType / assignCallResultOperand; #28670).
        $entry->methods['read'] = new XmlReaderRead();
        $entry->methodVisibility['read'] = $pub;
        $entry->methodNames['read'] = 'read';
        $entry->methods['close'] = new XmlReaderClose();
        $entry->methodVisibility['close'] = $pub;
        $entry->methodNames['close'] = 'close';
        $entry->methods['getattribute'] = new XmlReaderGetAttribute();
        $entry->methodVisibility['getattribute'] = $pub;
        $entry->methodNames['getattribute'] = 'getAttribute';
        $entry->methods['getattributeno'] = new XmlReaderGetAttributeNo();
        $entry->methodVisibility['getattributeno'] = $pub;
        $entry->methodNames['getattributeno'] = 'getAttributeNo';
        $entry->methods['getattributens'] = new XmlReaderGetAttributeNs();
        $entry->methodVisibility['getattributens'] = $pub;
        $entry->methodNames['getattributens'] = 'getAttributeNs';
        $entry->methods['isvalid'] = new XmlReaderIsValid();
        $entry->methodVisibility['isvalid'] = $pub;
        $entry->methodNames['isvalid'] = 'isValid';
        $entry->methods['expand'] = new XmlReaderExpand();
        $entry->methodVisibility['expand'] = $pub;
        $entry->methodNames['expand'] = 'expand';
        $entry->methods['readinnerxml'] = new XmlReaderReadInnerXml();
        $entry->methodVisibility['readinnerxml'] = $pub;
        $entry->methodNames['readinnerxml'] = 'readInnerXml';
        $entry->methods['readouterxml'] = new XmlReaderReadOuterXml();
        $entry->methodVisibility['readouterxml'] = $pub;
        $entry->methodNames['readouterxml'] = 'readOuterXml';
        $entry->methods['readstring'] = new XmlReaderReadString();
        $entry->methodVisibility['readstring'] = $pub;
        $entry->methodNames['readstring'] = 'readString';
        $entry->methods['movetoattribute'] = new XmlReaderMoveToAttribute();
        $entry->methodVisibility['movetoattribute'] = $pub;
        $entry->methodNames['movetoattribute'] = 'moveToAttribute';
        $entry->methods['movetoattributeno'] = new XmlReaderMoveToAttributeNo();
        $entry->methodVisibility['movetoattributeno'] = $pub;
        $entry->methodNames['movetoattributeno'] = 'moveToAttributeNo';
        $entry->methods['movetoattributens'] = new XmlReaderMoveToAttributeNs();
        $entry->methodVisibility['movetoattributens'] = $pub;
        $entry->methodNames['movetoattributens'] = 'moveToAttributeNs';
        $entry->methods['movetofirstattribute'] = new XmlReaderMoveToFirstAttribute();
        $entry->methodVisibility['movetofirstattribute'] = $pub;
        $entry->methodNames['movetofirstattribute'] = 'moveToFirstAttribute';
        $entry->methods['movetonextattribute'] = new XmlReaderMoveToNextAttribute();
        $entry->methodVisibility['movetonextattribute'] = $pub;
        $entry->methodNames['movetonextattribute'] = 'moveToNextAttribute';
        $entry->methods['movetoelement'] = new XmlReaderMoveToElement();
        $entry->methodVisibility['movetoelement'] = $pub;
        $entry->methodNames['movetoelement'] = 'moveToElement';
        $entry->methods['next'] = new XmlReaderNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methodNames['next'] = 'next';
        $entry->methods['lookupnamespace'] = new XmlReaderLookupNamespace();
        $entry->methodVisibility['lookupnamespace'] = $pub;
        $entry->methodNames['lookupnamespace'] = 'lookupNamespace';
        $entry->methods['setparserproperty'] = new XmlReaderSetParserProperty();
        $entry->methodVisibility['setparserproperty'] = $pub;
        $entry->methodNames['setparserproperty'] = 'setParserProperty';
        $entry->methods['getparserproperty'] = new XmlReaderGetParserProperty();
        $entry->methodVisibility['getparserproperty'] = $pub;
        $entry->methodNames['getparserproperty'] = 'getParserProperty';
        $entry->methods['setschema'] = new XmlReaderSetSchema();
        $entry->methodVisibility['setschema'] = $pub;
        $entry->methodNames['setschema'] = 'setSchema';
        $entry->methods['setrelaxngschema'] = new XmlReaderSetRelaxNGSchema();
        $entry->methodVisibility['setrelaxngschema'] = $pub;
        $entry->methodNames['setrelaxngschema'] = 'setRelaxNGSchema';
        $entry->methods['setrelaxngschemasource'] = new XmlReaderSetRelaxNGSchemaSource();
        $entry->methodVisibility['setrelaxngschemasource'] = $pub;
        $entry->methodNames['setrelaxngschemasource'] = 'setRelaxNGSchemaSource';

        if (CompilerVersion::supportsXmlReaderFactories()) {
            $entry->methods['fromstring'] = new XmlReaderFromString();
            $entry->methodVisibility['fromstring'] = $pubStatic;
            $entry->methodNames['fromstring'] = 'fromString';
            $entry->methods['fromuri'] = new XmlReaderFromUri();
            $entry->methodVisibility['fromuri'] = $pubStatic;
            $entry->methodNames['fromuri'] = 'fromUri';
            $entry->methods['fromstream'] = new XmlReaderFromStream();
            $entry->methodVisibility['fromstream'] = $pubStatic;
            $entry->methodNames['fromstream'] = 'fromStream';
            // php-src stub returns static (#27713).
            $staticRet = ReflectionTypeSupport::cfgTypeFromLabel('static');
            if (null !== $staticRet) {
                $entry->methodReturnDeclaredTypes['fromstring'] = $staticRet;
                $entry->methodReturnDeclaredTypes['fromuri'] = $staticRet;
                $entry->methodReturnDeclaredTypes['fromstream'] = $staticRet;
            }
        }

        // php-src php_xmlreader.stub.php — public typed properties for Reflection (#31639).
        // Runtime reads stay virtual via XmlReaderPropertySupport; slots are metadata only.
        self::registerReflectionProperties($entry, $pub);

        // php-src REGISTER_XMLREADER_CLASS_CONST_LONG — lc keys + constNames for
        // defined()/constant()/ReflectionClass::getConstant (#22349).
        XmlReaderConstants::registerOnClassEntry($entry);

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    /**
     * Register XMLReader public properties for Reflection (php-src stub; #31639).
     */
    private static function registerReflectionProperties(ClassEntry $entry, int $pub): void
    {
        $intProto = new Variable(Variable::TYPE_UNDEFINED);
        $intProto->declaredTypeLabel = 'int';
        $strProto = new Variable(Variable::TYPE_UNDEFINED);
        $strProto->declaredTypeLabel = 'string';
        $boolProto = new Variable(Variable::TYPE_UNDEFINED);
        $boolProto->declaredTypeLabel = 'bool';

        foreach ([
            self::PROP_ATTRIBUTE_COUNT => $intProto,
            self::PROP_BASE_URI => $strProto,
            self::PROP_DEPTH => $intProto,
            self::PROP_HAS_ATTRIBUTES => $boolProto,
            self::PROP_HAS_VALUE => $boolProto,
            self::PROP_IS_DEFAULT => $boolProto,
            self::PROP_IS_EMPTY_ELEMENT => $boolProto,
            self::PROP_LOCAL_NAME => $strProto,
            self::PROP_NAME => $strProto,
            self::PROP_NAMESPACE_URI => $strProto,
            self::PROP_NODE_TYPE => $intProto,
            self::PROP_PREFIX => $strProto,
            self::PROP_VALUE => $strProto,
            self::PROP_XML_LANG => $strProto,
        ] as $name => $proto) {
            $entry->properties[] = new ClassProperty(
                $name,
                null,
                $proto,
                false,
                $pub,
                self::CLASS_LC
            );
        }
    }

    public static function open(Context $ctx, string $uri, ?Frame $frame = null): ?ObjectEntry
    {
        $contents = self::readUriContents($ctx, $uri, $frame);
        if (null === $contents) {
            return null;
        }

        return self::openFromString($ctx, $uri, $contents, $frame);
    }

    /**
     * XMLReader::open() instance form — reset parser state on an existing reader (#19330).
     */
    public static function openOnto(Context $ctx, ObjectEntry $entry, string $uri, ?Frame $frame = null): bool
    {
        self::requireClass($entry, 'XMLReader::open()');
        $contents = self::readUriContents($ctx, $uri, $frame);
        if (null === $contents) {
            return false;
        }
        self::bindParsedSource($ctx, $entry, $uri, $contents, $frame);

        return true;
    }

    public static function openFromString(Context $ctx, string $uri, string $data, ?Frame $frame = null): ?ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('XMLReader is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        self::bindParsedSource($ctx, $entry, $uri, $data, $frame);

        return $entry;
    }

    private static function readUriContents(Context $ctx, string $uri, ?Frame $frame): ?string
    {
        $contents = VmFsReadNative::read($uri);
        if (false === $contents) {
            self::warn($ctx, 'XMLReader::open(): Unable to open source data', $frame);

            return null;
        }

        return $contents;
    }

    /**
     * XMLReader::XML() static factory — php-src zim_xmlreader_XML / xmlReaderForMemory (#19308).
     */
    public static function xml(Context $ctx, string $source, ?Frame $frame = null): ?ObjectEntry
    {
        return self::openFromString($ctx, '', $source, $frame);
    }

    /**
     * XMLReader::XML() instance form — reset parser state on an existing reader (#19308).
     */
    public static function xmlOnto(Context $ctx, ObjectEntry $entry, string $source, ?Frame $frame = null): bool
    {
        self::requireClass($entry, 'XMLReader::XML()');
        self::bindParsedSource($ctx, $entry, '', $source, $frame);

        return true;
    }

    /**
     * XMLReader::fromString() — always-static in-memory factory (php-src; #19607).
     */
    public static function fromString(Context $ctx, string $source, ?Frame $frame = null): ObjectEntry
    {
        $reader = self::openFromString($ctx, '', $source, $frame);
        if (null === $reader) {
            throw new \Error('XMLReader::fromString(): Unable to open source data');
        }

        return $reader;
    }

    /**
     * XMLReader::fromUri() — always-static URI factory (php-src; #19607).
     */
    public static function fromUri(Context $ctx, string $uri, ?Frame $frame = null): ?ObjectEntry
    {
        return self::open($ctx, $uri, $frame);
    }

    /**
     * XMLReader::fromStream() — always-static stream factory (php-src; #19607).
     */
    public static function fromStream(
        Context $ctx,
        Variable $streamVar,
        ?string $documentUri = null,
        ?Frame $frame = null
    ): ObjectEntry {
        $handle = ResourceSupport::resolveHandle($streamVar);
        if (null === $handle) {
            throw new \TypeError('XMLReader::fromStream(): Argument #1 ($stream) must be of type resource');
        }
        $contents = VmFs::streamGetContents($handle);
        if (false === $contents) {
            throw new \Error('XMLReader::fromStream(): Unable to read source data');
        }
        $uri = $documentUri ?? '';
        $reader = self::openFromString($ctx, $uri, $contents, $frame);
        if (null === $reader) {
            throw new \Error('XMLReader::fromStream(): Unable to open source data');
        }

        return $reader;
    }

    private static function bindParsedSource(
        Context $ctx,
        ObjectEntry $entry,
        string $uri,
        string $data,
        ?Frame $frame
    ): void {
        $parseErrorRecords = VmXml::validationErrorRecords($data);
        $valid = [] === $parseErrorRecords;
        $events = [];
        if ($valid) {
            try {
                $events = self::tokenize($data);
            } catch (\LogicException) {
                $valid = false;
                $parseErrorRecords = VmXml::validationErrorRecords($data);
            }
        }

        $state = new XmlReaderState();
        $state->uri = $uri;
        $state->sourceData = $data;
        $state->parseErrorRecords = $parseErrorRecords;
        $state->valid = $valid;
        $state->events = $events;
        $state->position = -1;
        $state->current = null;
        $state->attributeIndex = null;
        $state->closed = false;
        XmlReaderRegistry::attach($entry, $state);
    }

    private static function requireClass(ObjectEntry $entry, string $label): void
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLReader, %s given', $label, $entry->class->name));
        }
    }

    public static function read(Context $ctx, ObjectEntry $entry, ?Frame $frame = null): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->closed) {
            return false;
        }
        if (!$state->valid && !$state->readParseErrorsEmitted) {
            $state->readParseErrorsEmitted = true;
            self::emitReadParseErrors($ctx, $state, $frame);
            $state->current = null;

            return false;
        }

        $ok = self::advanceEvent($entry);
        if ($state->schemaModeActive || !empty($state->parserProps[XmlReaderConstants::VALIDATE])) {
            // Trigger deferred schema/DTD check on first successful or exhausting read (#19553).
            self::ensureSchemaValidation($entry);
        }

        return $ok;
    }

    /**
     * Advance the pull cursor without VM context (XMLReader::next / nextSibling; #19990).
     */
    private static function advanceEvent(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->closed) {
            return false;
        }
        if (!$state->valid && !$state->readParseErrorsEmitted) {
            return false;
        }
        $state->attributeIndex = null;
        ++$state->position;
        if ($state->position >= \count($state->events)) {
            $state->current = null;

            return false;
        }
        $state->current = $state->events[$state->position];

        return true;
    }

    public static function close(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $state->closed = true;
        $state->current = null;
        $state->attributeIndex = null;
        $state->position = \count($state->events);
        XmlReaderRegistry::detach($entry);

        return true;
    }

    public static function getAttribute(ObjectEntry $entry, string $name): ?string
    {
        $state = XmlReaderRegistry::state($entry);
        if (null !== $state->attributeIndex) {
            // On an attribute node, getAttribute() does not consult the parent element (php-src).
            return null;
        }
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return null;
        }

        return $current->attributes[$name] ?? null;
    }

    /**
     * XMLReader::getAttributeNo() — php-src zim_XMLReader_getAttributeNo / xmlTextReaderGetAttributeNo (#19412).
     */
    public static function getAttributeNo(ObjectEntry $entry, int $index): ?string
    {
        $state = XmlReaderRegistry::state($entry);
        if (null !== $state->attributeIndex) {
            // Positioned on an attribute node — libxml does not consult the parent element.
            return null;
        }
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return null;
        }
        // Negative indexes behave like 0 under libxml xmlTextReaderGetAttributeNo.
        if ($index < 0) {
            $index = 0;
        }
        $keys = array_keys($current->attributes);
        if (!isset($keys[$index])) {
            return null;
        }

        return $current->attributes[$keys[$index]];
    }

    /**
     * XMLReader::getAttributeNs() — php-src zim_XMLReader_getAttributeNs / xmlTextReaderGetAttributeNs (#19412).
     */
    public static function getAttributeNs(ObjectEntry $entry, string $localName, string $namespaceUri): ?string
    {
        $state = XmlReaderRegistry::state($entry);
        if (null !== $state->attributeIndex) {
            return null;
        }
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return null;
        }
        foreach ($current->attributes as $attrName => $value) {
            if (self::attributeLocalName($attrName) !== $localName) {
                continue;
            }
            if (self::attributeNamespaceUri($attrName, $current->nsScope) !== $namespaceUri) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private const XMLNS_NAMESPACE_URI = 'http://www.w3.org/2000/xmlns/';

    private static function attributeLocalName(string $attrName): string
    {
        if ('xmlns' === $attrName) {
            return 'xmlns';
        }
        if (str_starts_with($attrName, 'xmlns:')) {
            return substr($attrName, 6);
        }

        return self::splitQName($attrName)['local'];
    }

    /** @param array<string, string> $nsScope */
    private static function attributeNamespaceUri(string $attrName, array $nsScope): string
    {
        if ('xmlns' === $attrName || str_starts_with($attrName, 'xmlns:')) {
            return self::XMLNS_NAMESPACE_URI;
        }
        $parts = self::splitQName($attrName);
        if ('' === $parts['prefix']) {
            return '';
        }

        return $nsScope[$parts['prefix']] ?? '';
    }

    /**
     * XMLReader::lookupNamespace() — php-src zim_XMLReader_lookupNamespace / xmlTextReaderLookupNamespace (#19396).
     */
    public static function lookupNamespace(ObjectEntry $entry, string $prefix): ?string
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current) {
            return null;
        }

        return $current->nsScope[$prefix] ?? null;
    }

    /**
     * XMLReader::moveToAttribute() — php-src zim_XMLReader_moveToAttribute (#19395).
     */
    public static function moveToAttribute(ObjectEntry $entry, string $name): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return false;
        }
        $keys = array_keys($current->attributes);
        $idx = array_search($name, $keys, true);
        if (false === $idx) {
            return false;
        }
        $state->attributeIndex = $idx;

        return true;
    }

    /**
     * XMLReader::moveToAttributeNo() — php-src zim_XMLReader_moveToAttributeNo / xmlTextReaderMoveToAttributeNo (#19939).
     */
    public static function moveToAttributeNo(ObjectEntry $entry, int $index): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return false;
        }
        if ($index < 0) {
            $index = 0;
        }
        $keys = array_keys($current->attributes);
        if (!isset($keys[$index])) {
            return false;
        }
        $state->attributeIndex = $index;

        return true;
    }

    /**
     * XMLReader::moveToAttributeNs() — php-src zim_XMLReader_moveToAttributeNs / xmlTextReaderMoveToAttributeNs (#19939).
     */
    public static function moveToAttributeNs(ObjectEntry $entry, string $localName, string $namespaceUri): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return false;
        }
        $keys = array_keys($current->attributes);
        foreach ($keys as $idx => $attrName) {
            if (self::attributeLocalName($attrName) !== $localName) {
                continue;
            }
            if (self::attributeNamespaceUri($attrName, $current->nsScope) !== $namespaceUri) {
                continue;
            }
            $state->attributeIndex = $idx;

            return true;
        }

        return false;
    }

    /**
     * XMLReader::moveToFirstAttribute() — php-src zim_XMLReader_moveToFirstAttribute (#19395).
     */
    public static function moveToFirstAttribute(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return false;
        }
        if (0 === $current->attributeCount) {
            return false;
        }
        $state->attributeIndex = 0;

        return true;
    }

    /**
     * XMLReader::moveToNextAttribute() — php-src zim_XMLReader_moveToNextAttribute (#19395).
     */
    public static function moveToNextAttribute(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return false;
        }
        if (null === $state->attributeIndex) {
            return self::moveToFirstAttribute($entry);
        }
        $next = $state->attributeIndex + 1;
        if ($next >= $current->attributeCount) {
            return false;
        }
        $state->attributeIndex = $next;

        return true;
    }

    /**
     * XMLReader::moveToElement() — php-src zim_XMLReader_moveToElement (#19395).
     *
     * Returns true only when leaving an attribute cursor; false when already on the element.
     */
    public static function moveToElement(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if (null === $state->attributeIndex) {
            return false;
        }
        $state->attributeIndex = null;

        return true;
    }

    /**
     * XMLReader::next([string $name]) — php-src zim_XMLReader_next / xmlTextReaderNext (#19395).
     *
     * Skips the current node subtree to the following sibling-level node. Optional $name
     * keeps advancing until a node with that name is found (any node type).
     */
    public static function next(ObjectEntry $entry, ?string $name = null): bool
    {
        if (null === $name) {
            return self::nextSibling($entry);
        }
        while (self::nextSibling($entry)) {
            $view = self::currentEvent($entry);
            if (null !== $view && $name === $view->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Advance past the current node (and descendants) to the following document-order node.
     */
    private static function nextSibling(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->closed || null === $state->current) {
            $state->attributeIndex = null;

            return false;
        }
        // Attribute cursor is treated as the parent element (php-src).
        $state->attributeIndex = null;
        $current = $state->current;
        $depth = $current->depth;
        if (XmlReaderConstants::ELEMENT === $current->nodeType && !$current->isEmptyElement) {
            while (self::advanceEvent($entry)) {
                $ev = $state->current;
                if (null !== $ev
                    && XmlReaderConstants::END_ELEMENT === $ev->nodeType
                    && $ev->depth === $depth
                ) {
                    break;
                }
            }
            if (null === $state->current) {
                return false;
            }

            return self::advanceEvent($entry);
        }

        return self::advanceEvent($entry);
    }

    public static function isValid(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->schemaModeActive || !empty($state->parserProps[XmlReaderConstants::VALIDATE])) {
            // php-src xmlTextReaderIsValid: optimistic true before the first read under schema/VALIDATE.
            if ($state->position < 0) {
                return true;
            }
            self::ensureSchemaValidation($entry);

            return $state->schemaValid;
        }

        return $state->valid;
    }

    /**
     * XMLReader::setParserProperty() — php-src zim_XMLReader_setParserProperty (#19553).
     */
    public static function setParserProperty(ObjectEntry $entry, int $property, bool $value): bool
    {
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Cannot access parser properties before loading data');
        }
        if (!isset(XmlReaderRegistry::state($entry)->parserProps[$property])) {
            throw new \ValueError('XMLReader::setParserProperty(): Argument #1 ($property) must be a valid parser property');
        }
        $state = XmlReaderRegistry::state($entry);
        $state->parserProps[$property] = $value;
        if (XmlReaderConstants::VALIDATE === $property) {
            $state->schemaCheckDone = false;
            if ($value) {
                // Match php-src: isValid() becomes true before the first read once VALIDATE is on.
                $state->schemaValid = true;
            }
        }

        return true;
    }

    /**
     * XMLReader::getParserProperty() — php-src zim_XMLReader_getParserProperty (#19553).
     */
    public static function getParserProperty(ObjectEntry $entry, int $property): bool
    {
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Cannot access parser properties before loading data');
        }
        $state = XmlReaderRegistry::state($entry);
        if (!isset($state->parserProps[$property])) {
            throw new \ValueError('XMLReader::getParserProperty(): Argument #1 ($property) must be a valid parser property');
        }

        return $state->parserProps[$property];
    }

    /**
     * XMLReader::setSchema() — php-src zim_XMLReader_setSchema / xmlTextReaderSchemaValidate (#19553).
     */
    public static function setSchema(
        Context $ctx,
        ObjectEntry $entry,
        ?string $filename,
        ?Frame $frame = null
    ): bool {
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Schema must be set prior to reading');
        }
        $state = XmlReaderRegistry::state($entry);
        if ($state->position >= 0) {
            self::warn($ctx, 'XMLReader::setSchema(): Schema contains errors', $frame);

            return false;
        }
        if (null === $filename) {
            $state->schemaPath = null;
            if (null === $state->relaxNgPath) {
                $state->schemaModeActive = false;
            }
            $state->schemaCheckDone = false;
            $state->schemaValid = true;

            return true;
        }
        if ('' === $filename) {
            throw new \ValueError('XMLReader::setSchema(): Argument #1 ($filename) cannot be empty');
        }
        if (!is_file($filename)) {
            $schemaPath = $filename;
            if ('/' !== $schemaPath[0]) {
                $cwd = getcwd();
                if (false !== $cwd && '' !== $cwd) {
                    $schemaPath = rtrim($cwd, '/\\').'/'.$schemaPath;
                }
            }
            self::warn($ctx, 'XMLReader::setSchema(): I/O warning : failed to load external entity "'.$schemaPath.'"', $frame);
            self::warn($ctx, "XMLReader::setSchema(): Failed to locate the main schema resource at '{$schemaPath}'.", $frame);
            self::warn($ctx, 'XMLReader::setSchema(): Schema contains errors', $frame);

            return false;
        }
        if (!VmDomValidationNative::available() || !VmDomValidationNative::parseSchemaFile($filename)) {
            foreach (VmDomValidationNative::consumeLastErrors() as $error) {
                self::warn($ctx, 'XMLReader::setSchema(): '.$error['message'], $frame);
            }
            self::warn($ctx, 'XMLReader::setSchema(): Schema contains errors', $frame);

            return false;
        }
        $state->schemaPath = $filename;
        $state->relaxNgPath = null;
        $state->schemaModeActive = true;
        $state->schemaCheckDone = false;
        $state->schemaValid = true;

        return true;
    }

    /**
     * XMLReader::setRelaxNGSchema() — php-src zim_XMLReader_setRelaxNGSchema (#19553).
     */
    public static function setRelaxNGSchema(
        Context $ctx,
        ObjectEntry $entry,
        ?string $filename,
        ?Frame $frame = null
    ): bool {
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Schema must be set prior to reading');
        }
        $state = XmlReaderRegistry::state($entry);
        if ($state->position >= 0) {
            self::warn($ctx, 'XMLReader::setRelaxNGSchema(): Schema contains errors', $frame);

            return false;
        }
        if (null === $filename) {
            $state->relaxNgPath = null;
            $state->relaxNgSource = null;
            if (null === $state->schemaPath) {
                $state->schemaModeActive = false;
            }
            $state->schemaCheckDone = false;
            $state->schemaValid = true;

            return true;
        }
        if ('' === $filename) {
            throw new \ValueError('XMLReader::setRelaxNGSchema(): Argument #1 ($filename) cannot be empty');
        }
        if (!is_file($filename)) {
            $rngPath = $filename;
            if ('/' !== $rngPath[0]) {
                $cwd = getcwd();
                if (false !== $cwd && '' !== $cwd) {
                    $rngPath = rtrim($cwd, '/\\').'/'.$rngPath;
                }
            }
            self::warn($ctx, 'XMLReader::setRelaxNGSchema(): I/O warning : failed to load external entity "'.$rngPath.'"', $frame);
            self::warn($ctx, 'XMLReader::setRelaxNGSchema(): xmlRelaxNGParse: could not load '.$rngPath, $frame);
            self::warn($ctx, 'XMLReader::setRelaxNGSchema(): Schema contains errors', $frame);

            return false;
        }
        if (!VmDomValidationNative::available() || !VmDomValidationNative::parseRelaxNGFile($filename)) {
            foreach (VmDomValidationNative::consumeLastErrors() as $error) {
                self::warn($ctx, 'XMLReader::setRelaxNGSchema(): '.$error['message'], $frame);
            }
            self::warn($ctx, 'XMLReader::setRelaxNGSchema(): Schema contains errors', $frame);

            return false;
        }
        $state->relaxNgPath = $filename;
        $state->relaxNgSource = null;
        $state->schemaPath = null;
        $state->schemaModeActive = true;
        $state->schemaCheckDone = false;
        $state->schemaValid = true;

        return true;
    }

    /**
     * XMLReader::setRelaxNGSchemaSource() — php-src zim_XMLReader_setRelaxNGSchemaSource (#19940).
     */
    public static function setRelaxNGSchemaSource(
        Context $ctx,
        ObjectEntry $entry,
        ?string $source,
        ?Frame $frame = null
    ): bool {
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Schema must be set prior to reading');
        }
        $state = XmlReaderRegistry::state($entry);
        if ($state->position >= 0) {
            self::warn($ctx, 'XMLReader::setRelaxNGSchemaSource(): Schema contains errors', $frame);

            return false;
        }
        if (null === $source) {
            $state->relaxNgPath = null;
            $state->relaxNgSource = null;
            if (null === $state->schemaPath) {
                $state->schemaModeActive = false;
            }
            $state->schemaCheckDone = false;
            $state->schemaValid = true;

            return true;
        }
        if ('' === $source) {
            throw new \ValueError('XMLReader::setRelaxNGSchemaSource(): Argument #1 ($source) cannot be empty');
        }
        if (!VmDomValidationNative::available() || !VmDomValidationNative::parseRelaxNGSource($source)) {
            foreach (VmDomValidationNative::consumeLastErrors() as $error) {
                self::warn($ctx, 'XMLReader::setRelaxNGSchemaSource(): '.$error['message'], $frame);
            }
            self::warn($ctx, 'XMLReader::setRelaxNGSchemaSource(): Schema contains errors', $frame);

            return false;
        }
        $state->relaxNgSource = $source;
        $state->relaxNgPath = null;
        $state->schemaPath = null;
        $state->schemaModeActive = true;
        $state->schemaCheckDone = false;
        $state->schemaValid = true;

        return true;
    }

    /**
     * Apply deferred XSD / RelaxNG / DTD validation after the first read() (#19553 / #19940).
     */
    private static function ensureSchemaValidation(ObjectEntry $entry): void
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->schemaCheckDone) {
            return;
        }
        $state->schemaCheckDone = true;
        if (null !== $state->schemaPath) {
            if (!VmDomValidationNative::available()) {
                $state->schemaValid = false;

                return;
            }
            $state->schemaValid = VmDomValidationNative::validateSchemaDocument(
                $state->sourceData,
                $state->schemaPath
            );
            VmDomValidationNative::consumeLastErrors();

            return;
        }
        if (null !== $state->relaxNgSource) {
            if (!VmDomValidationNative::available()) {
                $state->schemaValid = false;

                return;
            }
            $state->schemaValid = VmDomValidationNative::validateRelaxNGDocumentSource(
                $state->sourceData,
                $state->relaxNgSource
            );
            VmDomValidationNative::consumeLastErrors();

            return;
        }
        if (null !== $state->relaxNgPath) {
            if (!VmDomValidationNative::available()) {
                $state->schemaValid = false;

                return;
            }
            $state->schemaValid = VmDomValidationNative::validateRelaxNGDocument(
                $state->sourceData,
                $state->relaxNgPath
            );
            VmDomValidationNative::consumeLastErrors();

            return;
        }
        if (!empty($state->parserProps[XmlReaderConstants::VALIDATE])) {
            if (!VmDomValidationNative::available()) {
                $state->schemaValid = false;

                return;
            }
            $result = VmDomValidationNative::validateDtdDocument($state->sourceData);
            $state->schemaValid = $result['valid'];
        }
    }

    /**
     * XMLReader::readInnerXml() — php-src zim_XMLReader_readInnerXml (#19411).
     */
    public static function readInnerXml(ObjectEntry $entry): string
    {
        self::requireClass($entry, 'XMLReader::readInnerXml()');
        $position = self::subtreePosition($entry);
        if (null === $position) {
            return '';
        }
        $state = XmlReaderRegistry::state($entry);

        return XmlReaderSubtreeXmlHelper::innerXml($state->events, $position);
    }

    /**
     * XMLReader::readOuterXml() — php-src zim_XMLReader_readOuterXml (#19411).
     *
     * Attribute cursor uses the parent element position (php-src).
     */
    public static function readOuterXml(ObjectEntry $entry): string
    {
        self::requireClass($entry, 'XMLReader::readOuterXml()');
        $position = self::subtreePosition($entry);
        if (null === $position) {
            return '';
        }
        $state = XmlReaderRegistry::state($entry);

        return XmlReaderSubtreeXmlHelper::outerXml($state->events, $position);
    }

    /**
     * XMLReader::readString() — php-src zim_XMLReader_readString (#19411).
     */
    public static function readString(ObjectEntry $entry): string
    {
        self::requireClass($entry, 'XMLReader::readString()');
        $position = self::subtreePosition($entry);
        if (null === $position) {
            return '';
        }
        $state = XmlReaderRegistry::state($entry);
        // Attribute nodes: php-src returns "" (unimplemented block warning on some builds).
        if (null !== $state->attributeIndex) {
            return '';
        }

        return XmlReaderSubtreeXmlHelper::readString($state->events, $position);
    }

    /**
     * Event index for subtree APIs, or null when the reader has no current node.
     */
    private static function subtreePosition(ObjectEntry $entry): ?int
    {
        if (!XmlReaderRegistry::has($entry)) {
            return null;
        }
        $state = XmlReaderRegistry::state($entry);
        if ($state->closed || null === $state->current || $state->position < 0) {
            return null;
        }

        return $state->position;
    }

    /**
     * XMLReader::expand() — php-src zim_XMLReader_expand / xmlTextReaderExpand (#19394).
     *
     * @return ObjectEntry|false
     */
    public static function expand(
        Context $ctx,
        ObjectEntry $entry,
        ?ObjectEntry $baseNode = null,
        ?Frame $frame = null
    ): ObjectEntry|false {
        self::requireClass($entry, 'XMLReader::expand()');
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Data must be loaded before expanding');
        }
        if (null !== $baseNode && !VmDom::isDomNode($baseNode)) {
            throw new \TypeError(
                'XMLReader::expand(): Argument #1 ($baseNode) must be of type ?DOMNode, '
                .$baseNode->class->name.' given'
            );
        }
        $ownerDocument = self::resolveExpandDocument($baseNode);
        $state = XmlReaderRegistry::state($entry);
        $node = XmlReaderExpandHelper::expandAt($ctx, $state->events, $state->position, $ownerDocument);
        if (false === $node) {
            self::warn($ctx, 'XMLReader::expand(): An Error Occurred while expanding', $frame);

            return false;
        }

        return $node;
    }

    private static function resolveExpandDocument(?ObjectEntry $baseNode): ?ObjectEntry
    {
        if (null === $baseNode) {
            return null;
        }
        if (VmDom::isDocument($baseNode)) {
            return $baseNode;
        }

        return VmDom::ownerDocumentEntry($baseNode);
    }

    public static function requireReader(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLReader, %s given', $label, $entry->class->name));
        }
        if (!XmlReaderRegistry::has($entry)) {
            throw new \LogicException($label.'(): XMLReader has no parser state');
        }

        return $entry;
    }

    public static function currentEvent(ObjectEntry $entry): ?XmlReaderEvent
    {
        if (!XmlReaderRegistry::has($entry)) {
            return null;
        }
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current) {
            return null;
        }
        if (null === $state->attributeIndex) {
            return $current;
        }
        if (XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return $current;
        }
        $keys = array_keys($current->attributes);
        $idx = $state->attributeIndex;
        if (!isset($keys[$idx])) {
            return $current;
        }
        $attrName = $keys[$idx];
        $attrValue = $current->attributes[$attrName];
        $nameParts = self::splitQName($attrName);
        // Attribute nodes always report hasValue=true (php-src / libxml), even for "".
        $event = self::makeEvent(
            XmlReaderConstants::ATTRIBUTE,
            $attrName,
            $attrValue,
            [],
            $current->depth + 1,
            false,
            $nameParts,
            $current->nsScope
        );
        $event->hasValue = true;

        return $event;
    }

    /** @return list<XmlReaderEvent> */
    public static function tokenize(string $data): array
    {
        $events = [];
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return $events;
        }

        $pos = 0;
        $len = \strlen($trimmed);
        /** @var list<array<string, string>> */
        $nsStack = [];
        while ($pos < $len) {
            $pos = self::skipWhitespace($trimmed, $pos);
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $trimmed[$pos]) {
                throw new \LogicException('XMLReader: expected element start');
            }
            if ($pos + 1 < $len && '?' === $trimmed[$pos + 1]) {
                self::consumeXmlDeclaration($trimmed, $pos);

                continue;
            }
            if ($pos + 1 < $len && '!' === $trimmed[$pos + 1]) {
                if (str_starts_with(substr($trimmed, $pos), '<!--')) {
                    self::consumeComment($trimmed, $pos);

                    continue;
                }
                if (str_starts_with(substr($trimmed, $pos), '<![CDATA[')) {
                    self::tokenizeCdata($trimmed, $pos, $events, 0, $nsStack);

                    continue;
                }
                if (str_starts_with(substr($trimmed, $pos), '<!DOCTYPE')) {
                    self::consumeDoctype($trimmed, $pos);

                    continue;
                }
            }
            if ($pos + 1 < $len && '/' === $trimmed[$pos + 1]) {
                throw new \LogicException('XMLReader: unexpected end tag');
            }

            self::tokenizeElement($trimmed, $pos, $events, 0, $nsStack);
        }

        return $events;
    }

    /**
     * @param list<XmlReaderEvent>        $events
     * @param list<array<string, string>> $nsStack
     */
    private static function tokenizeElement(string $data, int &$pos, array &$events, int $depth, array &$nsStack): void
    {
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $data, $open, 0, $pos)) {
            throw new \LogicException('XMLReader: malformed element');
        }

        $rawName = $open[1];
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $attrs = self::parseAttributes($attrSpec);
        $nameParts = self::splitQName($rawName);
        $decls = self::extractNamespaceDecls($attrs);
        $nsStack[] = $decls;
        $scope = self::mergeNamespaceScope($nsStack);
        $nameParts['uri'] = '' !== $nameParts['prefix']
            ? ($scope[$nameParts['prefix']] ?? '')
            : ($scope[''] ?? '');
        $events[] = self::makeEvent(
            XmlReaderConstants::ELEMENT,
            $rawName,
            '',
            $attrs,
            $depth,
            $selfClose,
            $nameParts,
            $scope
        );

        $contentStart = $pos + \strlen($open[0]);
        if ($selfClose) {
            array_pop($nsStack);
            $pos = $contentStart;

            return;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            throw new \LogicException('XMLReader: unclosed element');
        }

        $closeTag = '</'.$rawName.'>';
        $innerEnd = $end - \strlen($closeTag);
        $scan = $contentStart;
        while ($scan < $innerEnd) {
            $scan = self::skipWhitespace($data, $scan);
            if ($scan >= $innerEnd) {
                break;
            }
            if ('<' !== $data[$scan]) {
                $textEnd = strpos($data, '<', $scan);
                if (false === $textEnd || $textEnd > $innerEnd) {
                    $textEnd = $innerEnd;
                }
                $text = substr($data, $scan, $textEnd - $scan);
                if ('' !== $text) {
                    $events[] = self::makeEvent(
                        XmlReaderConstants::TEXT,
                        '#text',
                        $text,
                        [],
                        $depth + 1,
                        false,
                        ['local' => '#text', 'prefix' => '', 'uri' => ''],
                        $scope
                    );
                }
                $scan = $textEnd;

                continue;
            }
            if ($scan + 1 < $innerEnd && '!' === $data[$scan + 1]) {
                if (str_starts_with(substr($data, $scan), '<![CDATA[')) {
                    self::tokenizeCdata($data, $scan, $events, $depth + 1, $nsStack);

                    continue;
                }
                if (str_starts_with(substr($data, $scan), '<!--')) {
                    self::consumeComment($data, $scan);

                    continue;
                }
            }
            self::tokenizeElement($data, $scan, $events, $depth + 1, $nsStack);
        }

        $events[] = self::makeEvent(
            XmlReaderConstants::END_ELEMENT,
            $rawName,
            '',
            [],
            $depth,
            false,
            $nameParts,
            $scope
        );
        array_pop($nsStack);
        $pos = $end;
    }

    /**
     * @param list<XmlReaderEvent>        $events
     * @param list<array<string, string>> $nsStack
     */
    private static function tokenizeCdata(string $data, int &$pos, array &$events, int $depth, array &$nsStack): void
    {
        $parsed = VmXml::parseCdataSectionAt($data, $pos);
        if (null === $parsed) {
            throw new \LogicException('XMLReader: malformed CDATA');
        }
        $events[] = self::makeEvent(
            XmlReaderConstants::CDATA,
            '#cdata-section',
            $parsed['data'],
            [],
            $depth,
            false,
            ['local' => '#cdata-section', 'prefix' => '', 'uri' => ''],
            self::mergeNamespaceScope($nsStack)
        );
        $pos = $parsed['end'];
    }

    private static function consumeComment(string $data, int &$pos): void
    {
        $parsed = VmXml::parseCommentAt($data, $pos);
        if (null === $parsed) {
            throw new \LogicException('XMLReader: malformed comment');
        }
        $pos = $parsed['end'];
    }

    private static function consumeXmlDeclaration(string $data, int &$pos): void
    {
        $end = strpos($data, '?>', $pos + 2);
        if (false === $end) {
            throw new \LogicException('XMLReader: malformed XML declaration');
        }
        $pos = $end + 2;
    }

    private static function consumeDoctype(string $data, int &$pos): void
    {
        $end = strpos($data, '>', $pos + 9);
        if (false === $end) {
            throw new \LogicException('XMLReader: malformed DOCTYPE');
        }
        $pos = $end + 1;
    }

    /**
     * @param array<string, string>                       $attrs
     * @param array{local: string, prefix: string, uri: string} $nameParts
     * @param array<string, string>                       $nsScope
     */
    private static function makeEvent(
        int $nodeType,
        string $name,
        string $value,
        array $attrs,
        int $depth,
        bool $isEmptyElement,
        array $nameParts,
        array $nsScope = []
    ): XmlReaderEvent {
        $hasValue = '' !== $value;
        $attrCount = \count($attrs);

        return new XmlReaderEvent(
            $nodeType,
            $name,
            $value,
            $attrs,
            $depth,
            $isEmptyElement,
            $hasValue,
            $attrCount > 0,
            $attrCount,
            $nameParts['local'],
            $nameParts['prefix'],
            $nameParts['uri'],
            $nsScope
        );
    }

    /**
     * @param array<string, string> $attrs
     *
     * @return array<string, string> prefix → URI ('' key = default xmlns)
     */
    private static function extractNamespaceDecls(array $attrs): array
    {
        $decls = [];
        foreach ($attrs as $name => $value) {
            if ('xmlns' === $name) {
                $decls[''] = $value;

                continue;
            }
            if (str_starts_with($name, 'xmlns:')) {
                $decls[substr($name, 6)] = $value;
            }
        }

        return $decls;
    }

    /**
     * @param list<array<string, string>> $nsStack
     *
     * @return array<string, string>
     */
    private static function mergeNamespaceScope(array $nsStack): array
    {
        $scope = [];
        foreach ($nsStack as $decls) {
            foreach ($decls as $prefix => $uri) {
                $scope[$prefix] = $uri;
            }
        }

        return $scope;
    }

    /** @return array{local: string, prefix: string, uri: string} */
    private static function splitQName(string $qName): array
    {
        $colon = strpos($qName, ':');
        if (false === $colon) {
            return ['local' => $qName, 'prefix' => '', 'uri' => ''];
        }

        return [
            'local' => substr($qName, $colon + 1),
            'prefix' => substr($qName, 0, $colon),
            'uri' => '',
        ];
    }

    /** @return array<string, string> */
    private static function parseAttributes(string $attrSpec): array
    {
        $attrs = [];
        if ('' === trim($attrSpec)) {
            return $attrs;
        }
        if (!preg_match_all('/\G\s+([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $attrSpec, $matches, PREG_SET_ORDER)) {
            return $attrs;
        }
        foreach ($matches as $match) {
            $attrs[$match[1]] = $match[2] ?? $match[3];
        }

        return $attrs;
    }

    private static function skipWhitespace(string $data, int $pos): int
    {
        $len = \strlen($data);
        while ($pos < $len && ctype_space($data[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    /**
     * libxml triple-warning on first read() for malformed sources (php-src ext/xmlreader/php_xmlreader.c; #19933).
     */
    private static function emitReadParseErrors(Context $ctx, XmlReaderState $state, ?Frame $frame): void
    {
        $record = $state->parseErrorRecords[0] ?? null;
        if (null === $record) {
            return;
        }

        $prefix = 'XMLReader::read(): ';
        $location = self::xmlReaderErrorLocation($state);
        $libxmlMessage = self::xmlReaderLibxmlPrimaryMessage($state->sourceData, $record);
        VmLibxml::handleError(
            $ctx,
            $record,
            $frame,
            null,
            $prefix.$location.': parser error : '.$libxmlMessage
        );

        $snippet = trim($state->sourceData);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.$snippet);

        $caretColumn = self::xmlReaderCaretColumn($snippet, $record, $libxmlMessage);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.str_repeat(' ', $caretColumn).'^');
    }

    private static function xmlReaderErrorLocation(XmlReaderState $state): string
    {
        if ('' !== $state->uri) {
            return $state->uri.':1';
        }
        $cwd = getcwd();
        if (false === $cwd || '' === $cwd) {
            return ':1';
        }

        return rtrim($cwd, '/\\').'/'.':1';
    }

    /**
     * Map VM validation text to libxml xmlTextReader messages where they differ (#19933).
     *
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    private static function xmlReaderLibxmlPrimaryMessage(string $source, array $record): string
    {
        $trimmed = trim($source);
        if (str_contains($record['message'], 'Premature end of data in tag')
            && preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>$/s', $trimmed)) {
            return 'Extra content at the end of the document';
        }

        return $record['message'];
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    private static function xmlReaderCaretColumn(string $snippet, array $record, string $libxmlMessage): int
    {
        if ('Extra content at the end of the document' === $libxmlMessage) {
            return \strlen($snippet);
        }
        if (str_contains($record['message'], "Couldn't find end of Start Tag")) {
            return \strlen($snippet);
        }

        return max(0, $record['column'] - 1);
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                null,
                $frame->vmContext,
                $frame
            );
        } else {
            $ctx->errors->triggerError($message, ErrorReporter::E_WARNING, null, $ctx);
        }
    }
}
