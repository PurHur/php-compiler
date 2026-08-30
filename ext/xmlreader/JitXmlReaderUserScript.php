<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\dom\JitDomCreateCDATASection;
use PHPCompiler\ext\dom\JitDomCreateComment;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\ext\dom\JitDomDocumentElement;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT: XMLReader::XML / open / fromString + read() + nodeType/name/value
 * + readInnerXml / readOuterXml / expand (#27299, #28670, #35907, #35908, #35911).
 *
 * Thin standalone previously lowered factories to ExternalMethod NULL and then failed
 * `while ($r->read())` with `object::read()` once PHPCfg widened the receiver. Compile-time
 * tokenize via {@see VmXmlReader::tokenize} and emit a position switch that updates real
 * object slots so property fetches match Zend without NestedJIT of the full pull parser.
 *
 * Subtree serialize / expand APIs precompute per event index and switch on `__xr_pos`.
 * Expand materializes DOM via outer XML (peer importNode / loadXML child sync).
 *
 * php-src: ext/xmlreader/php_xmlreader.c — XML / open / fromString / read /
 * zim_XMLReader_readInnerXml / zim_XMLReader_readOuterXml / zim_XMLReader_expand
 */
final class JitXmlReaderUserScript
{
    public const PROP_POS = '__xr_pos';

    public const CLASS_NAME = 'XMLReader';

    /** @var list<array{nodeType: int, name: string, value: string}>|null */
    private static ?array $lastEvents = null;

    /** @var list<string>|null Precomputed readInnerXml() per event index (#35908). */
    private static ?array $lastInnerXml = null;

    /** @var list<string>|null Precomputed readOuterXml() per event index (#35908). */
    private static ?array $lastOuterXml = null;

    /**
     * Precomputed expand() materialize specs per event index (#35911).
     *
     * @var list<array{kind: string, name: string, text: string, outer: string, inner: string}>|null
     */
    private static ?array $lastExpand = null;

    /** Set when the last XML()/open lowering was the instance form (#35106 / #35907). */
    public static bool $lastCallWasInstance = false;

    /** Static open() may return false; skip object retag then (#35907). */
    public static bool $lastResultIsObject = true;

    public static function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    /**
     * @return list<array{nodeType: int, name: string, value: string}>|null
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
            $events[] = [
                'nodeType' => $ev->nodeType,
                'name' => $ev->name,
                'value' => $ev->value,
            ];
        }
        self::$lastEvents = $events;
        $inner = [];
        $outer = [];
        $expand = [];
        foreach ($rawEvents as $i => $_) {
            $innerXml = XmlReaderSubtreeXmlHelper::innerXml($rawEvents, $i);
            $outerXml = XmlReaderSubtreeXmlHelper::outerXml($rawEvents, $i);
            $inner[] = $innerXml;
            $outer[] = $outerXml;
            $expand[] = self::expandSpecForEvent($rawEvents[$i], $innerXml, $outerXml, $rawEvents, $i);
        }
        self::$lastInnerXml = $inner;
        self::$lastOuterXml = $outer;
        self::$lastExpand = $expand;

        if (null !== $instanceReceiver) {
            return self::resetReceiverForParse($context, $instanceReceiver);
        }

        return self::materializeReader($context);
    }

    /**
     * @param list<XmlReaderEvent> $rawEvents
     * @return array{kind: string, name: string, text: string, outer: string, inner: string}
     */
    private static function expandSpecForEvent(
        XmlReaderEvent $event,
        string $innerXml,
        string $outerXml,
        array $rawEvents,
        int $index
    ): array {
        return match ($event->nodeType) {
            XmlReaderConstants::ELEMENT,
            XmlReaderConstants::END_ELEMENT => [
                'kind' => 'element',
                'name' => $event->name,
                'text' => XmlReaderSubtreeXmlHelper::readString($rawEvents, $index),
                'outer' => $outerXml,
                'inner' => $innerXml,
            ],
            XmlReaderConstants::TEXT,
            XmlReaderConstants::WHITESPACE,
            XmlReaderConstants::SIGNIFICANT_WHITESPACE => [
                'kind' => 'text',
                'name' => '#text',
                'text' => $event->value,
                'outer' => '',
                'inner' => '',
            ],
            XmlReaderConstants::CDATA => [
                'kind' => 'cdata',
                'name' => '#cdata-section',
                'text' => $event->value,
                'outer' => '',
                'inner' => '',
            ],
            XmlReaderConstants::COMMENT => [
                'kind' => 'comment',
                'name' => '#comment',
                'text' => $event->value,
                'outer' => '',
                'inner' => '',
            ],
            default => [
                'kind' => 'false',
                'name' => '',
                'text' => '',
                'outer' => '',
                'inner' => '',
            ],
        };
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
        $zero = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(0, true)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE),
            $zero,
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_NAME),
            self::stringLlvm($context, ''),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_VALUE),
            self::stringLlvm($context, ''),
            JITVariable::TYPE_STRING
        );

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

        $empty = self::stringLlvm($context, '');
        $zero = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $i64->constInt(0, true)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE),
            $zero,
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_NAME),
            $empty,
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($reader, self::CLASS_NAME, VmXmlReader::PROP_VALUE),
            self::stringLlvm($context, ''),
            JITVariable::TYPE_STRING
        );

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
     * XMLReader::expand() leftover of fromString/open (#35911 / #27299 / #19394).
     * php-src: zim_XMLReader_expand / xmlTextReaderExpand.
     */
    public static function tryExpand(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || null === self::$lastExpand || null === self::$lastEvents) {
            return null;
        }
        if (\count($args) < 1) {
            throw new \LogicException('XMLReader::expand() called without $this');
        }
        // Optional DOMNode $baseNode is unsupported on the thin path (null owner).
        if (\count($args) > 1) {
            return null;
        }

        return self::emitExpandSwitch($context, $args[0], self::$lastExpand);
    }

    /**
     * @param list<array{kind: string, name: string, text: string, outer: string, inner: string}> $byPos
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
        $context->builder->store(self::boolFalseValueBox($context), $resultSlot);
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
            $context->builder->store(self::materializeExpandSpec($context, $byPos[$i]), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param array{kind: string, name: string, text: string, outer: string, inner: string} $spec
     */
    private static function materializeExpandSpec(Context $context, array $spec): Value
    {
        return match ($spec['kind']) {
            'element' => self::materializeExpandElement($context, $spec),
            'text' => self::objectValueBox(
                $context,
                JitDomCreateTextNode::materialize($context, $spec['text'])
            ),
            'cdata' => self::objectValueBox(
                $context,
                JitDomCreateCDATASection::materialize($context, $spec['text'])
            ),
            'comment' => self::objectValueBox(
                $context,
                JitDomCreateComment::materialize($context, $spec['text'])
            ),
            default => self::boolFalseValueBox($context),
        };
    }

    /**
     * @param array{kind: string, name: string, text: string, outer: string, inner: string} $spec
     */
    private static function materializeExpandElement(Context $context, array $spec): Value
    {
        if ('' === $spec['name']) {
            return self::boolFalseValueBox($context);
        }
        $element = JitDomCreateElement::materializeElementWithTextContent(
            $context,
            $spec['name'],
            $spec['text']
        );
        if ('' !== $spec['inner']) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $element, $spec['inner']);
        }
        if ('' !== $spec['outer']) {
            JitDomDocumentElement::syncChildrenFromXmlPublic($context, $element, $spec['outer']);
        }

        return self::objectValueBox($context, $element);
    }

    private static function objectValueBox(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boolFalseValueBox(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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
     * @param list<array{nodeType: int, name: string, value: string}> $events
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
            $ev = $events[$i];
            $nodeTypeVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($ev['nodeType'], true)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, self::CLASS_NAME, VmXmlReader::PROP_NODE_TYPE),
                $nodeTypeVar,
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
            $trueBox = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $trueBox, $i1->constInt(1, false));
            $context->builder->store(JitValueBox::normalizeValuePtr($context, $trueBox), $resultSlot);
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
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
        if (!$objectType->hasProperty($classId, VmXmlReader::PROP_NODE_TYPE)) {
            $objectType->defineProperty($classId, VmXmlReader::PROP_NODE_TYPE, JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($classId, VmXmlReader::PROP_NAME)) {
            $objectType->defineProperty($classId, VmXmlReader::PROP_NAME, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($classId, VmXmlReader::PROP_VALUE)) {
            $objectType->defineProperty($classId, VmXmlReader::PROP_VALUE, JITVariable::TYPE_STRING);
        }
    }
}
