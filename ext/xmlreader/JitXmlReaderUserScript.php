<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPCompiler\ext\dom\JitDomCreateCDATASection;
use PHPCompiler\ext\dom\JitDomCreateComment;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\ext\dom\JitDomDocumentElement;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT: XMLReader::XML / open / fromString + read() + nodeType/name/value
 * (#27299, #28670, #35907).
 *
 * Thin standalone previously lowered factories to ExternalMethod NULL and then failed
 * `while ($r->read())` with `object::read()` once PHPCfg widened the receiver. Compile-time
 * tokenize via {@see VmXmlReader::tokenize} and emit a position switch that updates real
 * object slots (nodeType/name/value/depth/localName/…) so property fetches match Zend
 * without NestedJIT of the full pull parser (#35983 leftover of #27299).
 *
 * php-src: ext/xmlreader/php_xmlreader.c — XML / open / fromString / read / xmlreader props
 */
final class JitXmlReaderUserScript
{
    public const PROP_POS = '__xr_pos';

    public const CLASS_NAME = 'XMLReader';

    /**
     * Compile-time event snapshots stamped onto the reader after each read() (#35983).
     *
     * @var list<array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }>|null
     */
    private static ?array $lastEvents = null;

    /** @var list<string>|null Precomputed readInnerXml() per event index (#35908). */
    private static ?array $lastInnerXml = null;

    /** @var list<string>|null Precomputed readOuterXml() per event index (#35908). */
    private static ?array $lastOuterXml = null;

    /** @var list<string>|null Precomputed readString() per event index (#35917 leftover of #35908). */
    private static ?array $lastReadString = null;

    /**
     * Precomputed expand() materialize plan per event index (#35911 / #19394).
     *
     * @var list<array{kind: string, xml?: string, data?: string}>|null
     */
    private static ?array $lastExpandSpec = null;

    /**
     * Per-event attribute maps for getAttribute() (#35918 leftover of #27299).
     *
     * @var list<array<string, string>>|null
     */
    private static ?array $lastAttributes = null;

    /**
     * Per-event prefix→URI maps for getAttributeNs / lookupNamespace (#35924 / #35930).
     *
     * @var list<array<string, string>>|null
     */
    private static ?array $lastNsScopes = null;

    /**
     * Per-event nodeType for ELEMENT-only attr lookups (#35924).
     *
     * @var list<int>|null
     */
    private static ?array $lastAttrNodeTypes = null;

    /**
     * Per-event next-sibling target index, or -1 when next() would return false (#35926).
     *
     * @var list<int>|null
     */
    private static ?array $lastNextSibling = null;

    /**
     * Parse validity for isValid() when no schema/VALIDATE mode is active (#35959 / #27299).
     * Mirrors {@see XmlReaderState::$valid} after {@see VmXmlReader::bindParsedSource}.
     */
    private static ?bool $lastValid = null;

    /**
     * Parser props for setParserProperty leftover (#35965 / #27299).
     * Mirrors {@see XmlReaderState::$parserProps} after bindParsedSource.
     *
     * @var array<int, bool>|null
     */
    private static ?array $lastParserProps = null;

    /** Set when the last XML()/open lowering was the instance form (#35106 / #35907). */
    public static bool $lastCallWasInstance = false;

    /** Static open() may return false; skip object retag then (#35907). */
    public static bool $lastResultIsObject = true;

    public static function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    /**
     * @return list<array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }>|null
     */
    public static function lastEvents(): ?array
    {
        return self::$lastEvents;
    }

    public static function tryFromString(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1) {
            return null;
        }
        self::$lastCallWasInstance = false;
        self::$lastResultIsObject = true;
        // Static factory: source is $args[0]. Instance XML() keeps EX(This) as $args[0]
        // with source in $args[1] (#22630 / #28670 / #35106).
        // Do NOT read compileTimeString from the object receiver — ARG_SEND may stamp the
        // source literal onto $this (#35106), which falsely selects the static-factory path.
        $instanceReceiver = null;
        $lit = null;
        if (isset($args[1])
            && (JITVariable::TYPE_OBJECT === $args[0]->type
                || JITVariable::TYPE_VALUE === $args[0]->type)
        ) {
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $lit && '' !== $lit) {
                $instanceReceiver = $args[0];
                self::$lastCallWasInstance = true;
            }
        }
        if (null === $lit || '' === $lit) {
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
            $instanceReceiver = null;
            self::$lastCallWasInstance = false;
        }
        if (null === $lit || '' === $lit) {
            return null;
        }

        return self::foldTokenizedSource($context, $lit, $instanceReceiver);
    }

    /**
     * XMLReader::open() leftover of fromUri/fromString (#35907 / #35900 / #27299).
     * php-src: zim_xmlreader_open — static returns XMLReader|false; instance returns bool.
     */
    public static function tryOpen(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1) {
            return null;
        }
        self::$lastCallWasInstance = false;
        self::$lastResultIsObject = true;
        $instanceReceiver = null;
        $uri = null;
        if (isset($args[1])
            && (JITVariable::TYPE_OBJECT === $args[0]->type
                || JITVariable::TYPE_VALUE === $args[0]->type)
        ) {
            $uri = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $uri && '' !== $uri && !str_starts_with($uri, '__phpc_')) {
                $instanceReceiver = $args[0];
                self::$lastCallWasInstance = true;
                self::$lastResultIsObject = false;
            }
        }
        if (null === $uri || '' === $uri || str_starts_with((string) $uri, '__phpc_')) {
            $uri = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
            $instanceReceiver = null;
            self::$lastCallWasInstance = false;
            self::$lastResultIsObject = true;
        }
        if (null === $uri || str_starts_with($uri, '__phpc_')) {
            return null;
        }
        if ('' === $uri) {
            throw new \ValueError('XMLReader::open(): Argument #1 ($uri) cannot be empty');
        }
        $xml = @file_get_contents($uri);
        if (false === $xml) {
            self::$lastResultIsObject = false;
            if (self::$lastCallWasInstance) {
                return $context->getTypeFromString('int1')->constInt(0, false);
            }
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }

        return self::foldTokenizedSource($context, $xml, $instanceReceiver);
    }

    /**
     * XMLReader::fromUri() leftover of fromString (#35900 / #27299).
     * php-src: zim_xmlreader_fromUri — host PHP 8.2 has no factory; read URI + tokenize.
     */
    public static function tryFromUri(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1) {
            return null;
        }
        self::$lastCallWasInstance = false;
        self::$lastResultIsObject = true;
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $uri || '' === $uri || str_starts_with($uri, '__phpc_')) {
            return null;
        }
        $xml = @file_get_contents($uri);
        if (false === $xml) {
            throw new \Error('XMLReader::fromUri(): Unable to open source data');
        }

        return self::foldTokenizedSource($context, $xml, null);
    }

    /**
     * XMLReader::fromStream() leftover of fromString (#35900 / #27299).
     * php-src: zim_xmlreader_fromStream — recover fopen literal path (#35895) then tokenize.
     */
    public static function tryFromStream(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1) {
            return null;
        }
        self::$lastCallWasInstance = false;
        self::$lastResultIsObject = true;
        $path = \PHPCompiler\ext\xmlwriter\JitXmlWriterUserScript::resolveFopenPath($args[0]);
        if (null === $path || '' === $path || str_starts_with($path, '__phpc_')) {
            return null;
        }
        $xml = @file_get_contents($path);
        if (false === $xml) {
            throw new \Error('XMLReader::fromStream(): Unable to open source data');
        }

        return self::foldTokenizedSource($context, $xml, null);
    }

    /**
     * Tokenize compile-time XML and materialize (or reset) a reader (#27299).
     *
     * @return Value|null
     */
    private static function foldTokenizedSource(Context $context, string $lit, ?JITVariable $instanceReceiver): ?Value
    {
        try {
            $raw = VmXmlReader::tokenize($lit);
        } catch (\Throwable) {
            return null;
        }

        $events = [];
        $rawEvents = [];
        foreach ($raw as $ev) {
            if (!$ev instanceof XmlReaderEvent) {
                continue;
            }
            $rawEvents[] = $ev;
            $events[] = self::eventPropsFromToken($ev);
        }
        self::$lastEvents = $events;
        // Same validity gate as VmXmlReader::bindParsedSource (#35959).
        self::$lastValid = [] === VmXml::validationErrorRecords($lit);
        self::$lastParserProps = [
            XmlReaderConstants::LOADDTD => false,
            XmlReaderConstants::DEFAULTATTRS => false,
            XmlReaderConstants::VALIDATE => false,
            XmlReaderConstants::SUBST_ENTITIES => false,
        ];
        $inner = [];
        $outer = [];
        $readString = [];
        $expand = [];
        $attributes = [];
        $nsScopes = [];
        $attrNodeTypes = [];
        $nextSibling = [];
        foreach ($rawEvents as $i => $ev) {
            $inner[] = XmlReaderSubtreeXmlHelper::innerXml($rawEvents, $i);
            $outerXml = XmlReaderSubtreeXmlHelper::outerXml($rawEvents, $i);
            $outer[] = $outerXml;
            $readString[] = XmlReaderSubtreeXmlHelper::readString($rawEvents, $i);
            $expand[] = self::expandSpecForEvent($ev, $outerXml);
            $attributes[] = $ev->attributes;
            $nsScopes[] = $ev->nsScope;
            $attrNodeTypes[] = $ev->nodeType;
            $nextSibling[] = self::nextSiblingTarget($rawEvents, $i);
        }
        self::$lastInnerXml = $inner;
        self::$lastOuterXml = $outer;
        self::$lastReadString = $readString;
        self::$lastExpandSpec = $expand;
        self::$lastAttributes = $attributes;
        self::$lastNsScopes = $nsScopes;
        self::$lastAttrNodeTypes = $attrNodeTypes;
        self::$lastNextSibling = $nextSibling;

        if (null !== $instanceReceiver) {
            return self::resetReceiverForParse($context, $instanceReceiver);
        }

        return self::materializeReader($context);
    }

    /**
     * Mirror {@see XmlReaderExpandHelper::expandAt} kinds for thin-AOT (#35911).
     *
     * @return array{kind: string, xml?: string, data?: string}
     */
    private static function expandSpecForEvent(XmlReaderEvent $ev, string $outerXml): array
    {
        return match ($ev->nodeType) {
            XmlReaderConstants::ELEMENT,
            XmlReaderConstants::END_ELEMENT => ['kind' => 'element', 'xml' => $outerXml],
            XmlReaderConstants::TEXT,
            XmlReaderConstants::WHITESPACE,
            XmlReaderConstants::SIGNIFICANT_WHITESPACE => ['kind' => 'text', 'data' => $ev->value],
            XmlReaderConstants::CDATA => ['kind' => 'cdata', 'data' => $ev->value],
            XmlReaderConstants::COMMENT => ['kind' => 'comment', 'data' => $ev->value],
            default => ['kind' => 'false'],
        };
    }

    /**
     * Target index for {@see VmXmlReader::nextSibling} from $i, or -1 if next() fails (#35926).
     *
     * @param list<XmlReaderEvent> $events
     */
    private static function nextSiblingTarget(array $events, int $i): int
    {
        $n = \count($events);
        if ($i < 0 || $i >= $n) {
            return -1;
        }
        $current = $events[$i];
        $depth = $current->depth;
        $j = $i;
        if (XmlReaderConstants::ELEMENT === $current->nodeType && !$current->isEmptyElement) {
            ++$j;
            while ($j < $n) {
                $ev = $events[$j];
                if (XmlReaderConstants::END_ELEMENT === $ev->nodeType && $ev->depth === $depth) {
                    break;
                }
                ++$j;
            }
            if ($j >= $n) {
                return -1;
            }
        }
        ++$j;

        return $j < $n ? $j : -1;
    }

    /**
     * Instance XML(): reset pull-parser slots on $this and return true (#35106).
     *
     * Allocating a fresh reader (static-factory path) left the caller's `$r` from
     * `new XMLReader()` uninitialized — `read()`/`->name` then SIGSEGVd.
     */
    private static function resetReceiverForParse(Context $context, JITVariable $receiver): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_xml_instance_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $reader = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $posVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(-1, true)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, self::PROP_POS),
            $posVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        self::storeEventProps($context, $objectType, $reader, self::emptyEventProps());

        // Return raw i1 so assignCallResultOperand keeps NATIVE_BOOL (not object box) (#35106).
        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function materializeReader(Context $context): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_from_string_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $reader = $objectType->allocate($classId);
        $objectType->markObjectConstructed($reader);

        $i64 = $context->getTypeFromString('int64');
        $posVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(-1, true)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, self::PROP_POS),
            $posVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        self::storeEventProps($context, $objectType, $reader, self::emptyEventProps());

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $reader
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    public static function tryRead(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()) {
            return null;
        }
        $events = self::$lastEvents;
        if (null === $events) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::read() called without $this');
        }

        return self::emitReadSwitch($context, $args[0], $events);
    }

    /**
     * XMLReader::readInnerXml() leftover of fromString read (#35908 / #27299 / #19411).
     * php-src: zim_XMLReader_readInnerXml
     */
    public static function tryReadInnerXml(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySubtreeXml($context, self::$lastInnerXml, 'readInnerXml', ...$args);
    }

    /**
     * XMLReader::readOuterXml() leftover of fromString read (#35908 / #27299 / #19411).
     * php-src: zim_XMLReader_readOuterXml
     */
    public static function tryReadOuterXml(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySubtreeXml($context, self::$lastOuterXml, 'readOuterXml', ...$args);
    }

    /**
     * XMLReader::readString() leftover of fromString/readInnerXml (#35917 / #35908 / #27299 / #19411).
     * php-src: zim_XMLReader_readString
     */
    public static function tryReadString(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySubtreeXml($context, self::$lastReadString, 'readString', ...$args);
    }

    /**
     * XMLReader::expand() leftover of fromString/open read (#35911 / #27299 / #19394).
     * php-src: zim_XMLReader_expand — optional $baseNode not folded (returns null → compile error).
     */
    public static function tryExpand(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastExpandSpec || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::expand() called without $this');
        }
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            // Owner-document baseNode needs DomRegistry — leave for NestedJIT later.
            return null;
        }

        return self::emitExpandSwitch($context, $args[0], self::$lastExpandSpec);
    }

    /**
     * XMLReader::getAttribute() leftover of fromString/read (#35918 / #27299 / #6135).
     * php-src: zim_XMLReader_getAttribute — compile-time name + __xr_pos attr map.
     */
    public static function tryGetAttribute(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastAttributes || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::getAttribute() expects $this and $name');
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            return null;
        }
        $byPos = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            $byPos[] = self::lookupAttributeAtPos($i, $name);
        }

        return self::emitPosNullableStringSwitch($context, $args[0], $byPos, 'getAttribute');
    }

    /**
     * XMLReader::getAttributeNs() leftover of getAttribute (#35924 / #35918 / #27299 / #19412).
     * php-src: zim_XMLReader_getAttributeNs / xmlTextReaderGetAttributeNs
     */
    public static function tryGetAttributeNs(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastNsScopes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 3) {
            throw new \LogicException('XMLReader::getAttributeNs() expects $this, $name, $namespace');
        }
        $local = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $ns = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $local || null === $ns) {
            return null;
        }
        if ('' === $local) {
            throw new \ValueError('XMLReader::getAttributeNs(): Argument #1 ($name) cannot be empty');
        }
        if ('' === $ns) {
            throw new \ValueError('XMLReader::getAttributeNs(): Argument #2 ($namespace) cannot be empty');
        }
        $byPos = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            $byPos[] = self::lookupAttributeNsAtPos($i, $local, $ns);
        }

        return self::emitPosNullableStringSwitch($context, $args[0], $byPos, 'getAttributeNs');
    }

    /**
     * XMLReader::getAttributeNo() leftover of getAttribute (#35924 / #35918 / #27299 / #19412).
     * php-src: zim_XMLReader_getAttributeNo / xmlTextReaderGetAttributeNo
     */
    public static function tryGetAttributeNo(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::getAttributeNo() expects $this and $index');
        }
        $index = self::compileTimeIntArg($context, $args[1]);
        if (null === $index) {
            return null;
        }
        // Negative indexes behave like 0 under libxml xmlTextReaderGetAttributeNo.
        if ($index < 0) {
            $index = 0;
        }
        $byPos = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            $byPos[] = self::lookupAttributeNoAtPos($i, $index);
        }

        return self::emitPosNullableStringSwitch($context, $args[0], $byPos, 'getAttributeNo');
    }

    /** @return ?string Attribute value at pos for exact name, or null (ELEMENT-only). */
    private static function lookupAttributeAtPos(int $pos, string $name): ?string
    {
        if (null === self::$lastAttrNodeTypes
            || !isset(self::$lastAttrNodeTypes[$pos])
            || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$pos]
        ) {
            // Pre-#35924 getAttribute stored attrs on every event; still honor ELEMENT-only php-src.
            if (null === self::$lastAttrNodeTypes && isset(self::$lastAttributes[$pos])) {
                $attrs = self::$lastAttributes[$pos];

                return \array_key_exists($name, $attrs) ? $attrs[$name] : null;
            }

            return null;
        }
        $attrs = self::$lastAttributes[$pos] ?? [];

        return \array_key_exists($name, $attrs) ? $attrs[$name] : null;
    }

    /** @return ?string Namespaced attribute value at pos, or null. */
    private static function lookupAttributeNsAtPos(int $pos, string $localName, string $namespaceUri): ?string
    {
        if (null === self::$lastAttrNodeTypes
            || !isset(self::$lastAttrNodeTypes[$pos])
            || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$pos]
        ) {
            return null;
        }
        $attrs = self::$lastAttributes[$pos] ?? [];
        $nsScope = self::$lastNsScopes[$pos] ?? [];
        foreach ($attrs as $attrName => $value) {
            if (VmXmlReader::attributeLocalNamePublic($attrName) !== $localName) {
                continue;
            }
            if (VmXmlReader::attributeNamespaceUriPublic($attrName, $nsScope) !== $namespaceUri) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /** @return ?string Attribute value by document-order index at pos, or null. */
    private static function lookupAttributeNoAtPos(int $pos, int $index): ?string
    {
        if (null === self::$lastAttrNodeTypes
            || !isset(self::$lastAttrNodeTypes[$pos])
            || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$pos]
        ) {
            return null;
        }
        $attrs = self::$lastAttributes[$pos] ?? [];
        $keys = array_keys($attrs);
        if (!isset($keys[$index])) {
            return null;
        }

        return $attrs[$keys[$index]];
    }

    private static function compileTimeIntArg(Context $context, JITVariable $var): ?int
    {
        if (null !== ($var->compileTimeLong ?? null)) {
            return (int) $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && null !== $var->value) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
            }
        }

        return null;
    }

    private static function compileTimeBoolArg(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeLong) {
            return 0 !== (int) $var->compileTimeLong;
        }
        if (null !== $var->compileTimeConstantName) {
            $cn = strtolower($var->compileTimeConstantName);
            if ('true' === $cn) {
                return true;
            }
            if ('false' === $cn) {
                return false;
            }
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type && JITVariable::KIND_VALUE === $var->kind && null !== $var->value) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }

    /**
     * XMLReader::lookupNamespace() leftover of fromString/getAttribute (#35930 / #27299 / #19396).
     * php-src: zim_XMLReader_lookupNamespace — compile-time prefix + __xr_pos nsScope.
     */
    public static function tryLookupNamespace(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastNsScopes || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::lookupNamespace() expects $this and $prefix');
        }
        $prefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $prefix) {
            return null;
        }
        if ('' === $prefix) {
            throw new \ValueError('XMLReader::lookupNamespace(): Argument #1 ($prefix) cannot be empty');
        }
        $byPos = [];
        foreach (self::$lastNsScopes as $scope) {
            $byPos[] = \array_key_exists($prefix, $scope) ? $scope[$prefix] : null;
        }

        return self::emitPosNullableStringSwitch($context, $args[0], $byPos, 'lookupNamespace');
    }

    /**
     * XMLReader::next() leftover of fromString/read (#35926 / #27299 / #19395).
     * php-src: zim_XMLReader_next / xmlTextReaderNext.
     * Optional $name must be compile-time (or omitted); runtime name leaves unfoldered.
     */
    public static function tryNext(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastNextSibling || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::next() called without $this');
        }
        $name = null;
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $name) {
                return null;
            }
        }
        $targets = self::$lastNextSibling;
        if (null !== $name) {
            $named = [];
            $events = self::$lastEvents;
            foreach ($targets as $i => $_) {
                $pos = $targets[$i];
                $hit = -1;
                while ($pos >= 0) {
                    if ($name === $events[$pos]['name']) {
                        $hit = $pos;
                        break;
                    }
                    $pos = $targets[$pos];
                }
                $named[$i] = $hit;
            }
            $targets = $named;
        }

        return self::emitNextSwitch($context, $args[0], $targets);
    }

    /**
     * XMLReader::isValid() leftover of fromString/read (#35959 / #27299 / #6135).
     * php-src: zim_XMLReader_isValid / xmlTextReaderIsValid.
     * Without schema/VALIDATE (not yet AOT-folded), returns stamped parse validity.
     */
    public static function tryIsValid(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastEvents || null === self::$lastValid) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::isValid() called without $this');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_isvalid_cont');
        // Touch receiver so lowering stays tied to the tracked reader object.
        self::loadObject($context, $args[0]);
        $i1 = $context->getTypeFromString('int1');
        $box = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $box,
            $i1->constInt(self::$lastValid ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $box);
    }

    /**
     * XMLReader::setParserProperty() leftover of fromString/read (#35965 / #27299 / #6135).
     * php-src: zim_XMLReader_setParserProperty / xmlTextReaderSetParserProp.
     * Compile-time property+value: stamp parserProps and return true (php-src always succeeds
     * for LOADDTD/DEFAULTATTRS/VALIDATE/SUBST_ENTITIES).
     */
    public static function trySetParserProperty(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastEvents || null === self::$lastParserProps) {
            return null;
        }
        if (\count($args) < 3) {
            throw new \LogicException('XMLReader::setParserProperty() expects $this, $property, $value');
        }
        $property = self::compileTimeIntArg($context, $args[1]);
        $value = self::compileTimeBoolArg($context, $args[2]);
        if (null === $property || null === $value) {
            return null;
        }
        if (!isset(self::$lastParserProps[$property])) {
            throw new \ValueError('XMLReader::setParserProperty(): Argument #1 ($property) must be a valid parser property');
        }
        self::$lastParserProps[$property] = $value;
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_setparserproperty_cont');
        self::loadObject($context, $args[0]);
        $i1 = $context->getTypeFromString('int1');
        $box = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $box, $i1->constInt(1, false));

        return JitValueBox::normalizeValuePtr($context, $box);
    }

    /**
     * XMLReader::setSchema() leftover of fromString (#35971 / #27299 / #19553).
     * php-src: zim_XMLReader_setSchema / xmlTextReaderSchemaValidate.
     * Compile-time null filename: clear schema and return true (php-src before first read).
     */
    public static function trySetSchema(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySchemaNullClear(
            $context,
            'setSchema',
            'filename',
            ...$args
        );
    }

    /**
     * XMLReader::setRelaxNGSchema() leftover of fromString (#35971 / #27299 / #19553).
     * php-src: zim_XMLReader_setRelaxNGSchema.
     */
    public static function trySetRelaxNGSchema(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySchemaNullClear(
            $context,
            'setRelaxNGSchema',
            'filename',
            ...$args
        );
    }

    /**
     * XMLReader::setRelaxNGSchemaSource() leftover of fromString (#35971 / #27299 / #19940).
     * php-src: zim_XMLReader_setRelaxNGSchemaSource.
     */
    public static function trySetRelaxNGSchemaSource(Context $context, JITVariable ...$args): ?Value
    {
        return self::trySchemaNullClear(
            $context,
            'setRelaxNGSchemaSource',
            'source',
            ...$args
        );
    }

    /**
     * Fold compile-time null/empty schema args. Non-null file/source paths stay unfolded.
     */
    private static function trySchemaNullClear(
        Context $context,
        string $method,
        string $paramName,
        JITVariable ...$args
    ): ?Value {
        if (!self::isUserScriptAot() || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::'.$method.'() expects $this and $'.$paramName);
        }
        if (self::isCompileTimeNull($args[1])) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_'.strtolower($method).'_cont');
            self::loadObject($context, $args[0]);
            $i1 = $context->getTypeFromString('int1');
            $box = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $box, $i1->constInt(1, false));

            return JitValueBox::normalizeValuePtr($context, $box);
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if ('' === $lit) {
            throw new \ValueError(
                'XMLReader::'.$method.'(): Argument #1 ($'.$paramName.') cannot be empty'
            );
        }

        return null;
    }

    private static function isCompileTimeNull(JITVariable $var): bool
    {
        if (JITVariable::TYPE_NULL === $var->type || ($var->isNullConstant ?? false)) {
            return true;
        }
        if (null !== $var->compileTimeConstantName
            && 'null' === strtolower($var->compileTimeConstantName)
        ) {
            return true;
        }

        return false;
    }

    /**
     * XMLReader::close() leftover of fromString/open (#35935 / #27299 / #6135).
     * php-src: zim_XMLReader_close / xmlTextReaderClose — return true; cursor past last event.
     * Subsequent read() is false (php-src xmlTextReaderRead on a closed reader).
     */
    public static function tryClose(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::close() called without $this');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_close_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $end = $i64->constInt(\count(self::$lastEvents), true);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_POS),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $end
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        self::storeEventProps($context, $objectType, $obj, self::emptyEventProps());
        $trueBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $trueBox, $i1->constInt(1, false));

        return JitValueBox::normalizeValuePtr($context, $trueBox);
    }

    /**
     * XMLReader::getParserProperty() leftover of fromString/read (#35962 / #27299 / #19553).
     * php-src: zim_XMLReader_getParserProperty / xmlTextReaderGetParserProp
     *
     * Defaults match {@see XmlReaderState::$parserProps} after tokenize folds; setParserProperty
     * is not yet folded, so compile-time property ints always return the stamped defaults.
     */
    public static function tryGetParserProperty(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::getParserProperty() expects $this and $property');
        }
        $property = self::compileTimeIntArg($context, $args[1]);
        if (null === $property) {
            return null;
        }
        $defaults = [
            XmlReaderConstants::LOADDTD => false,
            XmlReaderConstants::DEFAULTATTRS => false,
            XmlReaderConstants::VALIDATE => false,
            XmlReaderConstants::SUBST_ENTITIES => false,
        ];
        if (!\array_key_exists($property, $defaults)) {
            throw new \ValueError(
                'XMLReader::getParserProperty(): Argument #1 ($property) must be a valid parser property'
            );
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_getparserproperty_cont');
        $i1 = $context->getTypeFromString('int1');
        $box = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $box, $i1->constInt($defaults[$property] ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $box);
    }

    /**
     * XMLReader::moveToAttribute() leftover of getAttribute (#35941 / #35918 / #27299 / #19395).
     * php-src: zim_XMLReader_moveToAttribute / xmlTextReaderMoveToAttribute
     */
    public static function tryMoveToAttribute(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::moveToAttribute() expects $this and $name');
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            return null;
        }
        $hits = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            if (!isset(self::$lastAttrNodeTypes[$i])
                || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$i]
            ) {
                $hits[] = null;
                continue;
            }
            if (!\array_key_exists($name, $attrs)) {
                $hits[] = null;
                continue;
            }
            $hits[] = self::attributeEventProps($i, $name, $attrs[$name]);
        }

        return self::emitMoveToAttributeSwitch($context, $args[0], $hits, 'movetoattribute');
    }

    /**
     * XMLReader::moveToFirstAttribute() leftover of moveToAttribute (#35948 / #35941 / #27299 / #19395).
     * php-src: zim_XMLReader_moveToFirstAttribute / xmlTextReaderMoveToFirstAttribute
     */
    public static function tryMoveToFirstAttribute(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::moveToFirstAttribute() called without $this');
        }
        $hits = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            if (!isset(self::$lastAttrNodeTypes[$i])
                || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$i]
                || [] === $attrs
            ) {
                $hits[] = null;
                continue;
            }
            $name = (string) array_key_first($attrs);
            $hits[] = self::attributeEventProps($i, $name, $attrs[$name]);
        }

        return self::emitMoveToAttributeSwitch($context, $args[0], $hits, 'movetofirstattribute');
    }

    /**
     * XMLReader::moveToAttributeNo() leftover of moveToAttribute (#35946 / #35941 / #27299 / #19939).
     * php-src: zim_XMLReader_moveToAttributeNo / xmlTextReaderMoveToAttributeNo
     */
    public static function tryMoveToAttributeNo(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 2) {
            throw new \LogicException('XMLReader::moveToAttributeNo() expects $this and $index');
        }
        $index = self::compileTimeIntArg($context, $args[1]);
        if (null === $index) {
            return null;
        }
        // Negative indexes behave like 0 under libxml xmlTextReaderMoveToAttributeNo.
        if ($index < 0) {
            $index = 0;
        }
        $hits = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            if (!isset(self::$lastAttrNodeTypes[$i])
                || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$i]
            ) {
                $hits[] = null;
                continue;
            }
            $keys = array_keys($attrs);
            if (!isset($keys[$index])) {
                $hits[] = null;
                continue;
            }
            $name = $keys[$index];
            $hits[] = self::attributeEventProps($i, $name, $attrs[$name]);
        }

        return self::emitMoveToAttributeSwitch($context, $args[0], $hits, 'movetoattributeno');
    }

    /**
     * XMLReader::moveToAttributeNs() leftover of moveToAttribute (#35951 / #35941 / #27299).
     * php-src: zim_XMLReader_moveToAttributeNs / xmlTextReaderMoveToAttributeNs
     */
    public static function tryMoveToAttributeNs(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastNsScopes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 3) {
            throw new \LogicException('XMLReader::moveToAttributeNs() expects $this, $name, $namespace');
        }
        $local = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $ns = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $local || null === $ns) {
            return null;
        }
        if ('' === $local) {
            throw new \ValueError('XMLReader::moveToAttributeNs(): Argument #1 ($name) cannot be empty');
        }
        if ('' === $ns) {
            throw new \ValueError('XMLReader::moveToAttributeNs(): Argument #2 ($namespace) cannot be empty');
        }
        $hits = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            $hits[] = self::lookupMoveToAttributeNsHitAtPos($i, $local, $ns);
        }

        return self::emitMoveToAttributeSwitch($context, $args[0], $hits, 'movetoattributens');
    }

    /**
     * @return ?array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }
     */
    private static function lookupMoveToAttributeNsHitAtPos(
        int $pos,
        string $localName,
        string $namespaceUri
    ): ?array {
        if (null === self::$lastAttrNodeTypes
            || !isset(self::$lastAttrNodeTypes[$pos])
            || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$pos]
        ) {
            return null;
        }
        $attrs = self::$lastAttributes[$pos] ?? [];
        $nsScope = self::$lastNsScopes[$pos] ?? [];
        foreach ($attrs as $attrName => $value) {
            if (VmXmlReader::attributeLocalNamePublic($attrName) !== $localName) {
                continue;
            }
            if (VmXmlReader::attributeNamespaceUriPublic($attrName, $nsScope) !== $namespaceUri) {
                continue;
            }

            return self::attributeEventProps($pos, $attrName, $value);
        }

        return null;
    }

    /**
     * Move the AOT cursor onto an attribute node (or leave it unchanged on miss) (#35941).
     *
     * @param list<?array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }> $hits
     */
    private static function emitMoveToAttributeSwitch(
        Context $context,
        JITVariable $receiver,
        array $hits,
        string $label = 'movetoattribute'
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_'.$label.'_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_merge');
        $miss = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_miss');

        $n = \count($hits);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            $hit = $hits[$i];
            if (null === $hit) {
                $missBox = JitValueBox::alloc($context);
                JitValueBox::writeBool($context, $missBox, $i1->constInt(0, false));
                $context->builder->store(JitValueBox::normalizeValuePtr($context, $missBox), $resultSlot);
            } else {
                self::storeEventProps($context, $objectType, $obj, $hit);
                $trueBox = JitValueBox::alloc($context);
                JitValueBox::writeBool($context, $trueBox, $i1->constInt(1, false));
                $context->builder->store(JitValueBox::normalizeValuePtr($context, $trueBox), $resultSlot);
            }
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * XMLReader::moveToNextAttribute() leftover of moveToAttribute (#35952 / #35941 / #27299 / #19395).
     * php-src: zim_XMLReader_moveToNextAttribute / xmlTextReaderMoveToNextAttribute
     *
     * When not on an attribute node, behaves like moveToFirstAttribute. When on an attribute,
     * advances by matching the current name against document-order keys at __xr_pos.
     */
    public static function tryMoveToNextAttribute(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || null === self::$lastAttributes
            || null === self::$lastAttrNodeTypes
            || null === self::$lastEvents
        ) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::moveToNextAttribute() called without $this');
        }
        /** @var list<list<array{
         *   nodeType: int,
         *   name: string,
         *   value: string,
         *   depth: int,
         *   localName: string,
         *   prefix: string,
         *   namespaceUri: string,
         *   attributeCount: int,
         *   hasAttributes: bool,
         *   hasValue: bool,
         *   isEmptyElement: bool,
         *   xmlLang: string,
         *   isDefault: bool,
         *   baseURI: string
         * }>> $byPos */
        $byPos = [];
        foreach (self::$lastAttributes as $i => $attrs) {
            if (!isset(self::$lastAttrNodeTypes[$i])
                || XmlReaderConstants::ELEMENT !== self::$lastAttrNodeTypes[$i]
            ) {
                $byPos[] = [];
                continue;
            }
            $list = [];
            foreach ($attrs as $name => $value) {
                $list[] = self::attributeEventProps($i, (string) $name, (string) $value);
            }
            $byPos[] = $list;
        }

        return self::emitMoveToNextAttribute($context, $args[0], $byPos);
    }

    /**
     * @param list<list<array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }>> $byPos
     */
    private static function emitMoveToNextAttribute(
        Context $context,
        JITVariable $receiver,
        array $byPos
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_mtna_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );
        $nodeTypeVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE)
        );
        $nameStr = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, VmXmlReader::PROP_NAME)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_mtna_merge');
        $miss = BasicBlockHelper::append($context, 'xmlreader_mtna_miss');

        $n = \count($byPos);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_mtna_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $applyPos = BasicBlockHelper::append($context, 'xmlreader_mtna_pos_'.$i);
            $nextPos = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $applyPos, $nextPos);

            $context->builder->positionAtEnd($applyPos);
            $attrs = $byPos[$i];
            if ([] === $attrs) {
                $emptyBox = JitValueBox::alloc($context);
                JitValueBox::writeBool($context, $emptyBox, $i1->constInt(0, false));
                $context->builder->store(JitValueBox::normalizeValuePtr($context, $emptyBox), $resultSlot);
                $context->builder->branch($merge);
                continue;
            }

            $onAttr = BasicBlockHelper::append($context, 'xmlreader_mtna_onattr_'.$i);
            $fromElem = BasicBlockHelper::append($context, 'xmlreader_mtna_fromelem_'.$i);
            $isOnAttr = $context->builder->icmp(
                Builder::INT_EQ,
                $nodeTypeVal,
                $i64->constInt(XmlReaderConstants::ATTRIBUTE, true)
            );
            $context->builder->branchIf($isOnAttr, $onAttr, $fromElem);

            // Not on attribute → first attribute (php-src moveToNextAttribute).
            $context->builder->positionAtEnd($fromElem);
            self::storeAttributeCursorHit($context, $objectType, $obj, $attrs[0], $i64);
            $tb = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $tb, $i1->constInt(1, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $tb), $resultSlot);
            $context->builder->branch($merge);

            // On attribute → match current name, advance to next key.
            $context->builder->positionAtEnd($onAttr);
            $attrCount = \count($attrs);
            /** @var list<\PHPLLVM\BasicBlock> */
            $attrBlocks = [];
            for ($j = 0; $j < $attrCount; ++$j) {
                $attrBlocks[$j] = BasicBlockHelper::append($context, 'xmlreader_mtna_attr_'.$i.'_'.$j);
            }
            $attrMiss = BasicBlockHelper::append($context, 'xmlreader_mtna_attrmiss_'.$i);
            $context->builder->branch($attrBlocks[0]);

            for ($j = 0; $j < $attrCount; ++$j) {
                $context->builder->positionAtEnd($attrBlocks[$j]);
                $lit = $context->builder->load(
                    $context->constantStringFromString($attrs[$j]['name'])
                );
                $match = JitStringCompare::identical($context, $nameStr, $lit);
                $matched = BasicBlockHelper::append($context, 'xmlreader_mtna_matched_'.$i.'_'.$j);
                $tryNext = ($j + 1 < $attrCount) ? $attrBlocks[$j + 1] : $attrMiss;
                $context->builder->branchIf($match, $matched, $tryNext);

                $context->builder->positionAtEnd($matched);
                if ($j + 1 < $attrCount) {
                    self::storeAttributeCursorHit($context, $objectType, $obj, $attrs[$j + 1], $i64);
                    $okBox = JitValueBox::alloc($context);
                    JitValueBox::writeBool($context, $okBox, $i1->constInt(1, false));
                    $context->builder->store(JitValueBox::normalizeValuePtr($context, $okBox), $resultSlot);
                } else {
                    $endBox = JitValueBox::alloc($context);
                    JitValueBox::writeBool($context, $endBox, $i1->constInt(0, false));
                    $context->builder->store(JitValueBox::normalizeValuePtr($context, $endBox), $resultSlot);
                }
                $context->builder->branch($merge);
            }

            $context->builder->positionAtEnd($attrMiss);
            $noMatch = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $noMatch, $i1->constInt(0, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $noMatch), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * } $hit
     * @param mixed $i64 unused — kept for call-site compatibility
     */
    private static function storeAttributeCursorHit(
        Context $context,
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $obj,
        array $hit,
        $i64
    ): void {
        self::storeEventProps($context, $objectType, $obj, $hit);
    }

    /**
     * XMLReader::moveToElement() leftover of moveToAttribute (#35940 / #27299 / #19395).
     * php-src: zim_XMLReader_moveToElement — true only when leaving an attribute cursor.
     * Detects ATTRIBUTE nodeType set by {@see tryMoveToAttribute}, restores element props.
     */
    public static function tryMoveToElement(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::moveToElement() called without $this');
        }

        return self::emitMoveToElement($context, $args[0], self::$lastEvents);
    }

    /**
     * @param list<array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }> $events
     */
    private static function emitMoveToElement(
        Context $context,
        JITVariable $receiver,
        array $events
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_mte_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $nodeTypeVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE)
        );
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_mte_merge');
        $notOnAttr = BasicBlockHelper::append($context, 'xmlreader_mte_not_attr');
        $onAttr = BasicBlockHelper::append($context, 'xmlreader_mte_on_attr');

        $isOnAttr = $context->builder->icmp(
            Builder::INT_EQ,
            $nodeTypeVal,
            $i64->constInt(XmlReaderConstants::ATTRIBUTE, true)
        );
        $context->builder->branchIf($isOnAttr, $onAttr, $notOnAttr);

        $context->builder->positionAtEnd($notOnAttr);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($onAttr);
        $n = \count($events);
        $miss = BasicBlockHelper::append($context, 'xmlreader_mte_miss');
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_mte_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $tb0 = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $tb0, $i1->constInt(1, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $tb0), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_mte_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            self::storeEventProps($context, $objectType, $obj, $events[$i]);
            $tb = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $tb, $i1->constInt(1, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $tb), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * Materialize DOMNode|false for the current __xr_pos (after a successful read).
     *
     * @param list<array{kind: string, xml?: string, data?: string}> $byPos
     */
    private static function emitExpandSwitch(
        Context $context,
        JITVariable $receiver,
        array $byPos
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_expand_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_expand_merge');
        $miss = BasicBlockHelper::append($context, 'xmlreader_expand_miss');

        $n = \count($byPos);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_expand_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_expand_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            $context->builder->store(
                self::expandSpecValueBox($context, $byPos[$i]),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param array{kind: string, xml?: string, data?: string} $spec
     */
    private static function expandSpecValueBox(Context $context, array $spec): Value
    {
        $i1 = $context->getTypeFromString('int1');
        if ('false' === $spec['kind']) {
            $falseBox = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));

            return JitValueBox::normalizeValuePtr($context, $falseBox);
        }

        $node = match ($spec['kind']) {
            'element' => self::materializeExpandElement($context, (string) ($spec['xml'] ?? '')),
            'text' => JitDomCreateTextNode::materialize($context, (string) ($spec['data'] ?? '')),
            'cdata' => JitDomCreateCDATASection::materialize($context, (string) ($spec['data'] ?? '')),
            'comment' => JitDomCreateComment::materialize($context, (string) ($spec['data'] ?? '')),
            default => null,
        };
        if (null === $node) {
            $falseBox = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));

            return JitValueBox::normalizeValuePtr($context, $falseBox);
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $node
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** Build a stand-alone DOMElement tree from expand outer XML (#35911). */
    private static function materializeExpandElement(Context $context, string $xml): Value
    {
        $xml = trim($xml);
        if ('' === $xml) {
            return JitDomCreateElement::materializeElementFromLiteral($context, 'r');
        }
        $tag = DomParseSimpleXmlJitHelper::rootTagArgv($xml);
        $text = DomParseSimpleXmlJitHelper::rootTextContentArgv($xml);
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $rootMarkup = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($xml);
        $rootOpen = '';
        if (null !== $rootMarkup) {
            $rootOpen = '<'.$tag.$rootMarkup['attrs'].'>';
        }
        $element = JitDomDocumentElement::materializeElementFromXmlTag(
            $context,
            $tag,
            $text,
            $rootOpen
        );
        JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
        if (null !== $rootMarkup && '' !== $rootMarkup['attrs']) {
            JitDomCreateElement::storeUserScriptXmlnsAttr($context, $element, $rootMarkup['attrs']);
        }
        JitDomCreateElement::storeAttributesPresence(
            $context,
            $element,
            DomParseSimpleXmlJitHelper::rootAttributesArgv($xml)
        );
        JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $xml, '/'.$tag, null);

        return $element;
    }

    /**
     * @param list<string>|null $byPos
     */
    private static function trySubtreeXml(
        Context $context,
        ?array $byPos,
        string $label,
        JITVariable ...$args
    ): ?Value {
        if (!self::isUserScriptAot() || null === $byPos || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::'.$label.'() called without $this');
        }

        return self::emitPosStringSwitch($context, $args[0], $byPos, $label);
    }

    /**
     * Return precomputed string for the current __xr_pos (after a successful read).
     *
     * @param list<string> $byPos
     */
    private static function emitPosStringSwitch(
        Context $context,
        JITVariable $receiver,
        array $byPos,
        string $label
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_'.$label.'_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_merge');
        $miss = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_miss');

        $n = \count($byPos);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $context->builder->store(self::stringValueBox($context, ''), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            $context->builder->store(self::stringValueBox($context, $byPos[$i]), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * Return precomputed ?string for the current __xr_pos (getAttribute; #35918).
     *
     * @param list<?string> $byPos
     */
    private static function emitPosNullableStringSwitch(
        Context $context,
        JITVariable $receiver,
        array $byPos,
        string $label
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_'.$label.'_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_merge');
        $miss = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_miss');

        $n = \count($byPos);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $miss,
            $miss
        );

        $context->builder->positionAtEnd($miss);
        $context->builder->store(self::nullValueBox($context), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_'.$label.'_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $miss;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            $val = $byPos[$i];
            if (null === $val) {
                $context->builder->store(self::nullValueBox($context), $resultSlot);
            } else {
                $context->builder->store(self::stringValueBox($context, $val), $resultSlot);
            }
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    private static function nullValueBox(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** Boxed __value__* string constant for call results. */
    private static function stringValueBox(Context $context, string $xml): Value
    {
        $str = $context->builder->load($context->constantStringFromString($xml));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * @param list<array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }> $events
     */
    private static function emitReadSwitch(Context $context, JITVariable $receiver, array $events): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_read_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');

        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );
        $nextPos = $context->builder->add($posVal, $i64->constInt(1, true));
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_POS),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $nextPos
            ),
            JITVariable::TYPE_NATIVE_LONG
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_read_merge');
        $exhausted = BasicBlockHelper::append($context, 'xmlreader_read_exhausted');

        $n = \count($events);
        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_read_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $nextPos,
            $i64->constInt($n, true)
        );
        $context->builder->branchIf(
            $inRange,
            $n > 0 ? $caseBlocks[0] : $exhausted,
            $exhausted
        );

        $context->builder->positionAtEnd($exhausted);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $nextPos,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_read_apply_'.$i);
            $next = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $exhausted;
            $context->builder->branchIf($isThis, $apply, $next);

            $context->builder->positionAtEnd($apply);
            self::storeEventProps($context, $objectType, $obj, $events[$i]);
            $trueBox = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $trueBox, $i1->constInt(1, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $trueBox), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * Jump __xr_pos to precomputed next-sibling (or named) target (#35926).
     *
     * @param list<int> $targets per-position destination index, or -1 for false
     */
    private static function emitNextSwitch(Context $context, JITVariable $receiver, array $targets): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlreader_next_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        self::ensureLayout($objectType, $classId);

        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $events = self::$lastEvents;
        if (null === $events) {
            throw new \LogicException('XMLReader::next() without tokenized events');
        }
        $n = \count($targets);

        $posVal = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_NAME, self::PROP_POS)
        );

        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $merge = BasicBlockHelper::append($context, 'xmlreader_next_merge');
        $fail = BasicBlockHelper::append($context, 'xmlreader_next_fail');

        /** @var list<\PHPLLVM\BasicBlock> */
        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBlocks[$i] = BasicBlockHelper::append($context, 'xmlreader_next_case_'.$i);
        }
        $inRange = $context->builder->icmp(
            Builder::INT_SLT,
            $posVal,
            $i64->constInt($n, true)
        );
        $nonNeg = $context->builder->icmp(
            Builder::INT_SGE,
            $posVal,
            $i64->constInt(0, true)
        );
        $ok = $context->builder->and($inRange, $nonNeg);
        $context->builder->branchIf(
            $ok,
            $n > 0 ? $caseBlocks[0] : $fail,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $falseBox = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $falseBox, $i1->constInt(0, false));
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $falseBox), $resultSlot);
        $context->builder->branch($merge);

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $isThis = $context->builder->icmp(
                Builder::INT_EQ,
                $posVal,
                $i64->constInt($i, true)
            );
            $apply = BasicBlockHelper::append($context, 'xmlreader_next_apply_'.$i);
            $nextCase = ($i + 1 < $n) ? $caseBlocks[$i + 1] : $fail;
            $context->builder->branchIf($isThis, $apply, $nextCase);

            $context->builder->positionAtEnd($apply);
            $dest = $targets[$i];
            if ($dest < 0) {
                $objectType->propertyStore(
                    $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_POS),
                    new JITVariable(
                        $context,
                        JITVariable::TYPE_NATIVE_LONG,
                        JITVariable::KIND_VALUE,
                        $i64->constInt($n, true)
                    ),
                    JITVariable::TYPE_NATIVE_LONG
                );
                $fb = JitValueBox::alloc($context);
                JitValueBox::writeBool($context, $fb, $i1->constInt(0, false));
                $context->builder->store(JitValueBox::normalizeValuePtr($context, $fb), $resultSlot);
                $context->builder->branch($merge);
                continue;
            }
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_POS),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $i64->constInt($dest, true)
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
            self::storeEventProps($context, $objectType, $obj, $events[$dest]);
            $tb = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $tb, $i1->constInt(1, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $tb), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * Snapshot one tokenized event for AOT property stamps (#35983 / php-src xmlreader props).
     *
     * @return array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }
     */
    private static function eventPropsFromToken(XmlReaderEvent $ev): array
    {
        return [
            'nodeType' => $ev->nodeType,
            'name' => $ev->name,
            'value' => $ev->value,
            'depth' => $ev->depth,
            'localName' => $ev->localName,
            'prefix' => $ev->prefix,
            'namespaceUri' => $ev->namespaceUri,
            'attributeCount' => $ev->attributeCount,
            'hasAttributes' => $ev->hasAttributes,
            'hasValue' => $ev->hasValue,
            'isEmptyElement' => $ev->isEmptyElement,
            // isDefault / baseURI: string-source folds have no DTD default / URI.
            'xmlLang' => $ev->xmlLang,
            'isDefault' => false,
            'baseURI' => '',
        ];
    }

    /**
     * Empty / closed-reader virtual props (php-src after close / before first read).
     *
     * @return array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }
     */
    private static function emptyEventProps(): array
    {
        return [
            'nodeType' => 0,
            'name' => '',
            'value' => '',
            'depth' => 0,
            'localName' => '',
            'prefix' => '',
            'namespaceUri' => '',
            'attributeCount' => 0,
            'hasAttributes' => false,
            'hasValue' => false,
            'isEmptyElement' => false,
            'xmlLang' => '',
            'isDefault' => false,
            'baseURI' => '',
        ];
    }

    /**
     * Attribute-cursor props (php-src xmlTextReaderMoveToAttribute*; #35983).
     *
     * @return array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * }
     */
    private static function attributeEventProps(int $pos, string $attrName, string $attrValue): array
    {
        $ev = self::$lastEvents[$pos] ?? self::emptyEventProps();
        $nsScope = self::$lastNsScopes[$pos] ?? [];
        $colon = strpos($attrName, ':');
        if (false === $colon) {
            $prefix = '';
            $local = $attrName;
        } else {
            $prefix = substr($attrName, 0, $colon);
            $local = substr($attrName, $colon + 1);
        }

        return [
            'nodeType' => XmlReaderConstants::ATTRIBUTE,
            'name' => $attrName,
            'value' => $attrValue,
            'depth' => $ev['depth'] + 1,
            'localName' => $local,
            'prefix' => $prefix,
            'namespaceUri' => VmXmlReader::attributeNamespaceUriPublic($attrName, $nsScope),
            'attributeCount' => 0,
            'hasAttributes' => false,
            // Attribute nodes always report hasValue=true (php-src / libxml), even for "".
            'hasValue' => true,
            'isEmptyElement' => false,
            'xmlLang' => $ev['xmlLang'],
            'isDefault' => false,
            'baseURI' => $ev['baseURI'],
        ];
    }

    /**
     * @param array{
     *   nodeType: int,
     *   name: string,
     *   value: string,
     *   depth: int,
     *   localName: string,
     *   prefix: string,
     *   namespaceUri: string,
     *   attributeCount: int,
     *   hasAttributes: bool,
     *   hasValue: bool,
     *   isEmptyElement: bool,
     *   xmlLang: string,
     *   isDefault: bool,
     *   baseURI: string
     * } $ev
     */
    private static function storeEventProps(
        Context $context,
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        Value $obj,
        array $ev
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($ev['nodeType'], true)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_NAME),
            self::stringLlvm($context, $ev['name']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_VALUE),
            self::stringLlvm($context, $ev['value']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_DEPTH),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($ev['depth'], true)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_LOCAL_NAME),
            self::stringLlvm($context, $ev['localName']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_PREFIX),
            self::stringLlvm($context, $ev['prefix']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_NAMESPACE_URI),
            self::stringLlvm($context, $ev['namespaceUri']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_ATTRIBUTE_COUNT),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($ev['attributeCount'], true)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_HAS_ATTRIBUTES),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($ev['hasAttributes'] ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_HAS_VALUE),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($ev['hasValue'] ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_IS_EMPTY_ELEMENT),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($ev['isEmptyElement'] ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_IS_DEFAULT),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($ev['isDefault'] ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_XML_LANG),
            self::stringLlvm($context, $ev['xmlLang']),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_BASE_URI),
            self::stringLlvm($context, $ev['baseURI']),
            JITVariable::TYPE_STRING
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        $valPtr = JitValueBox::valuePtrFromVariable($context, $receiver);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
    }

    private static function stringLlvm(Context $context, string $s): JITVariable
    {
        $str = $context->builder->load($context->constantStringFromString($s));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );

        return new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
    }

    private static function ensureLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        if (!$objectType->hasProperty($classId, self::PROP_POS)) {
            $objectType->defineProperty($classId, self::PROP_POS, JITVariable::TYPE_NATIVE_LONG);
        }
        $defs = [
            VmXmlReader::PROP_NODE_TYPE => JITVariable::TYPE_NATIVE_LONG,
            VmXmlReader::PROP_NAME => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_VALUE => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_DEPTH => JITVariable::TYPE_NATIVE_LONG,
            VmXmlReader::PROP_LOCAL_NAME => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_PREFIX => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_NAMESPACE_URI => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_ATTRIBUTE_COUNT => JITVariable::TYPE_NATIVE_LONG,
            VmXmlReader::PROP_HAS_ATTRIBUTES => JITVariable::TYPE_NATIVE_BOOL,
            VmXmlReader::PROP_HAS_VALUE => JITVariable::TYPE_NATIVE_BOOL,
            VmXmlReader::PROP_IS_EMPTY_ELEMENT => JITVariable::TYPE_NATIVE_BOOL,
            VmXmlReader::PROP_IS_DEFAULT => JITVariable::TYPE_NATIVE_BOOL,
            VmXmlReader::PROP_XML_LANG => JITVariable::TYPE_STRING,
            VmXmlReader::PROP_BASE_URI => JITVariable::TYPE_STRING,
        ];
        foreach ($defs as $prop => $type) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, $type);
            }
        }
    }
}
