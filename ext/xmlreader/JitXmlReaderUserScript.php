<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT: XMLReader::XML / fromString + read() + nodeType/name/value (#27299, #28670).
 *
 * Thin standalone previously lowered factories to ExternalMethod NULL and then failed
 * `while ($r->read())` with `object::read()` once PHPCfg widened the receiver. Compile-time
 * tokenize via {@see VmXmlReader::tokenize} and emit a position switch that updates real
 * object slots so property fetches match Zend without NestedJIT of the full pull parser.
 *
 * php-src: ext/xmlreader/php_xmlreader.c — XML / fromString / read / xmlreader props
 */
final class JitXmlReaderUserScript
{
    public const PROP_POS = '__xr_pos';

    public const CLASS_NAME = 'XMLReader';

    /** @var list<array{nodeType: int, name: string, value: string}>|null */
    private static ?array $lastEvents = null;

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
        // Static factory: source is $args[0]. Instance XML()/open() may keep EX(This) as
        // $args[0] with source in $args[1] (#22630 / #28670).
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if ((null === $lit || '' === $lit) && isset($args[1])) {
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        }
        if (null === $lit || '' === $lit) {
            return null;
        }

        try {
            $raw = VmXmlReader::tokenize($lit);
        } catch (\Throwable) {
            return null;
        }

        $events = [];
        foreach ($raw as $ev) {
            if (!$ev instanceof XmlReaderEvent) {
                continue;
            }
            $events[] = [
                'nodeType' => $ev->nodeType,
                'name' => $ev->name,
                'value' => $ev->value,
            ];
        }
        self::$lastEvents = $events;

        return self::materializeReader($context);
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
