<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplFixedArray — object `__spl_ht` packed storage (#26793).
 *
 * php-src: ext/spl/spl_fixedarray.c — construct / fromArray / count / ArrayAccess / foreach.
 */
final class SplFixedArrayJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const CLASS_NAME = 'SplFixedArray';

    public static function compileConstruct(Context $context, JITVariable $receiver, ?JITVariable $sizeArg): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
        if (null !== $sizeArg) {
            $sizeLit = $sizeArg->compileTimeLong;
            if (null !== $sizeLit) {
                $n = (int) $sizeLit;
                if ($n < 0) {
                    throw new \ValueError(
                        'SplFixedArray::__construct(): Argument #1 ($size) must be greater than or equal to 0'
                    );
                }
                for ($i = 0; $i < $n; ++$i) {
                    $nullSlot = JitValueBox::alloc($context);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($context, $nullSlot)
                    );
                    $nullVar = new JITVariable(
                        $context,
                        JITVariable::TYPE_VALUE,
                        JITVariable::KIND_VARIABLE,
                        $nullSlot
                    );
                    $htVar->nextFreeElementFromRuntime = true;
                    HashTableHelper::addElement($context, $htVar, $nullVar, null);
                }
            } else {
                $size = self::coerceNonNegativeSize($context, $sizeArg, 'SplFixedArray::__construct');
                self::padNullSlots($context, $htVar, $size);
            }
        }
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    /**
     * SplFixedArray::fromArray($array, $preserveKeys = true) — static factory.
     *
     * Thin AOT: packed `__spl_ht` copy (dense int keys). `preserveKeys=false` reindexes
     * via {@see HashTableHelper::coerceToPackedHashtable}; true keeps the packed shape for
     * sequential arrays (issue repro). Sparse preserveKeys holes remain VM-covered.
     */
    public static function compileFromArray(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Static — user argc is frame argc (#30836; zim_SplFixedArray_fromArray).
        if (0 === $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'SplFixedArray::fromArray() expects at least 1 argument, 0 given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'splfixedarray_fromarray_argc_cont');
            $out = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $out)
            );

            return $out;
        }
        if ($argc > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('SplFixedArray::fromArray() expects at most 2 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'splfixedarray_fromarray_argc_cont');
            $out = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $out)
            );

            return $out;
        }
        $classId = $context->type->object->lookup(self::CLASS_NAME);
        $obj = $context->type->object->allocate($classId);
        $objectType = $context->type->object;

        $src = HashTableHelper::coerceToPackedHashtable($context, $args[0]);
        $htVar = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto($context, $htVar, $src);

        $slot = $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
        $objectType->markObjectConstructed($obj);

        $out = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $out),
            $obj
        );

        return $out;
    }

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        // Fixed size is nextFreeElement (slot count including null pads). numElements grows
        // when setAtIndex overwrites a null pad (offsetIsSet is false for TYPE_NULL) (#27285).
        $n = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    public static function compileGetSize(Context $context, JITVariable $receiver): Value
    {
        return self::compileCount($context, $receiver);
    }

    public static function compileOffsetGet(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $idx = self::coerceIndex($context, $index, 'SplFixedArray::offsetGet');
        self::assertIndexInRange($context, $ht, $idx, 'SplFixedArray::offsetGet');

        return HashTableHelper::readIndexedToValueBox($context, $ht, $idx)->value;
    }

    public static function compileOffsetSet(
        Context $context,
        JITVariable $receiver,
        JITVariable $index,
        JITVariable $value
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $idx = self::coerceIndex($context, $index, 'SplFixedArray::offsetSet');
        self::assertIndexInRange($context, $ht, $idx, 'SplFixedArray::offsetSet');
        HashTableHelper::setAtIndex($context, $ht, $idx, $value);

        return self::voidResult($context);
    }

    public static function compileOffsetExists(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idx = self::coerceIndex($context, $index, 'SplFixedArray::offsetExists');
        $sizeT = $context->getTypeFromString('size_t');
        $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $n);
        // isset: in-range and non-null (php-src spl_fixedarray_object_has_dimension).
        $out = JitValueBox::alloc($context);
        $missBb = BasicBlockHelper::append($context, 'sfa_exists_miss');
        $checkBb = BasicBlockHelper::append($context, 'sfa_exists_check');
        $doneBb = BasicBlockHelper::append($context, 'sfa_exists_done');
        $context->builder->branchIf($inRange, $checkBb, $missBb);

        $context->builder->positionAtEnd($missBb);
        JitValueBox::writeBool($context, $out, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $fetched);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valueMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $notNull = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        JitValueBox::writeBool($context, $out, $notNull);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    public static function compileOffsetUnset(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $idx = self::coerceIndex($context, $index, 'SplFixedArray::offsetUnset');
        self::assertIndexInRange($context, $ht, $idx, 'SplFixedArray::offsetUnset');
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $nullSlot);
        HashTableHelper::setAtIndex($context, $ht, $idx, $nullVar);

        return self::voidResult($context);
    }

    /** Return fixed size as int64 for count() builtin (#26793 / #27285). */
    public static function countAsInt64(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));

        return $context->builder->zExt(
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('size_t')),
            $context->getTypeFromString('int64')
        );
    }

    private static function padNullSlots(Context $context, JITVariable $htVar, Value $sizeI64): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $size = $context->builder->truncOrBitCast($sizeI64, $sizeT);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $condBb = BasicBlockHelper::append($context, 'sfa_pad_cond');
        $bodyBb = BasicBlockHelper::append($context, 'sfa_pad_body');
        $doneBb = BasicBlockHelper::append($context, 'sfa_pad_done');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $more = $context->builder->icmp(Builder::INT_ULT, $i, $size);
        $context->builder->branchIf($more, $bodyBb, $doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $nullSlot);
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $nullVar, null);
        $context->builder->store(
            $context->builder->add($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function coerceNonNegativeSize(Context $context, JITVariable $arg, string $fn): Value
    {
        if (null !== $arg->compileTimeLong) {
            $lit = (int) $arg->compileTimeLong;
            if ($lit < 0) {
                throw new \ValueError(
                    $fn.'(): Argument #1 ($size) must be greater than or equal to 0'
                );
            }

            return $context->getTypeFromString('int64')->constInt($lit, false);
        }

        return JitIntdiv::lowerIntBuiltinArgForCaller($context, $arg, $fn, 1, 'size');
    }

    private static function coerceIndex(Context $context, JITVariable $index, string $fn): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (null !== $index->compileTimeLong) {
            $lit = (int) $index->compileTimeLong;
            if ($lit < 0) {
                return $sizeT->constInt(0x7fffffffffffffff, false);
            }

            return $sizeT->constInt($lit, false);
        }
        $i64 = JitIntdiv::lowerIntBuiltinArgForCaller($context, $index, $fn, 1, 'index');

        return $context->builder->truncOrBitCast($i64, $sizeT);
    }

    private static function assertIndexInRange(Context $context, Value $ht, Value $idx, string $fn): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        // Bounds are the fixed slot count (nextFree), not numElements (#27285).
        $n = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $ok = $context->builder->icmp(Builder::INT_ULT, $idx, $n);
        $badBb = BasicBlockHelper::append($context, 'sfa_oob');
        $okBb = BasicBlockHelper::append($context, 'sfa_oob_ok');
        $context->builder->branchIf($ok, $okBb, $badBb);
        $context->builder->positionAtEnd($badBb);
        $msg = 'Index invalid or out of range';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            $context->builder->pointerCast($context->constantFromString($msg), $context->getTypeFromString('int8*')),
            $context->constantFromInteger(\strlen($msg), 'size_t')
        );
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($okBb);
    }

    /**
     * php-src SplFixedArray serialize — integer-keyed elements from `__spl_ht` (#33634 / #33639).
     *
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33625.
     * Null holes require exportKeyValuePairs to keep TYPE_NULL (#33639).
     *
     * @return Value {@see __string__*} full `O:len:"SplFixedArray":N:{…}` wire
     */
    public static function compileSerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $classNameStr = \PHPCompiler\JIT\ReflectionBuiltinHelper::getClassName($context, $objVar);
        $ht = self::htPtr($context, $obj);
        $logical = 'PHPCompiler\\ext\\standard\\SerializeSplFixedArrayNestedJitHelper::encodeWire';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/SerializeSplFixedArrayNestedJitHelper.php',
            [$logical],
            '#33639'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#33639');
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $args = [
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classNameStr,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classLen,
                $fn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $ht,
                $fn->getParam(2)->typeOf()
            ),
        ];
        $raw = $context->builder->call($fn, ...$args);
        $strPtr = $context->getTypeFromString('__string__*');

        return \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr);
    }

    /**
     * Thin AOT: restore SplFixedArray O: integer-keyed bag into `__spl_ht` (#33640).
     *
     * Avoids {@see StringUnserialize::emitObjectDecodeRuntime} writing firstIntProp into
     * slot 0 (corrupts `__spl_ht`). Prefer helper-runtime (peer #32925).
     * php-src: SplFixedArray::__unserialize / var_unserializer.c
     */
    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureObjectHelpersCompiled($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_null_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $findLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplFixedArrayFindNestedJitHelper::afterBrace';
        $fillLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplFixedArrayFillNestedJitHelper::fillAt';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplFixedArrayFindNestedJitHelper.php',
            [$findLogical],
            '#33640'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplFixedArrayFillNestedJitHelper.php',
            [$fillLogical],
            '#33640'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);

        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        $i64 = $context->getTypeFromString('int64');
        $findFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $findLogical, '#33640');
        $fillFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $fillLogical, '#33640');
        $offRaw = $context->builder->call(
            $findFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $findFn->getParam(0)->typeOf()
            )
        );
        $off = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $offRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbFill = $parent->appendBasicBlock('sfa_unser_fill');
        $bbDone = $parent->appendBasicBlock('sfa_unser_done');
        $found = $context->builder->icmp(
            Builder::INT_SGE,
            $off,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($found, $bbFill, $bbDone);

        $context->builder->positionAtEnd($bbFill);
        $ht = self::htPtr($context, $obj);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        $context->builder->call(
            $fillFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fillFn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fillFn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $off,
                $fillFn->getParam(2)->typeOf()
            )
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** Owned `__string__*` copy for NestedJIT PHP string params (#24137 / #33640). */
    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    /** Expose object load for {@see \PHPCompiler\ext\standard\JitSerialize} (#33634). */
    public static function loadObjectPtr(Context $context, JITVariable $receiver): Value
    {
        return self::loadObject($context, $receiver);
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SplFixedArray method requires an object receiver');
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
