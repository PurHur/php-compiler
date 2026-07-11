<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\array_combine;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\standard\JitArrayCountRecursive;
use PHPCompiler\ext\standard\floatval;
use PHPCompiler\ext\standard\intval;
use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\ext\standard\string_ltrim;
use PHPCompiler\ext\standard\string_rtrim;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\strtoupper;
use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\ext\types\strlen;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayMapRuntime;
use PHPCompiler\JIT\Builtin\ArrayReduceRuntime;
use PHPCompiler\JIT\Builtin\SortRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ArrayBuiltinHelper
{
    private const ARRAY_PRODUCT_ELEMENT_TYPE_ERROR =
        'array_product(): Argument #1 ($array) must contain only int and float values';

    /** Monotonic id so copyListEntry basic blocks stay unique per LLVM function. */
    private static int $copyListEntrySeq = 0;

    public static function isNativeArray(int $type): bool
    {
        return 0 !== ($type & Variable::IS_NATIVE_ARRAY);
    }

    public static function loadHashTable(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            return HashTableHelper::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_STRING === $array->type) {
            throw new \LogicException(
                'Expected array (hashtable), got string (path strings cannot be used as arrays in this compiler build)'
            );
        }

        throw new \LogicException(
            'Expected array (hashtable), got '.Variable::getStringType($array->type)
        );
    }

    public static function getNumElements(Context $context, Value $ht): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load(
            $context->builder->structGep($ht, $map['numElements'])
        );

        return $context->builder->zExt($num, $context->getTypeFromString('int64'));
    }

    /**
     * count($array, COUNT_RECURSIVE) — mirrors VmArray::countRecursive (#3511, #4584).
     */
    public static function countRecursive(Context $context, Variable $array): Value
    {
        $ht = self::isNativeArray($array->type)
            ? self::nativeListToHashTable($context, $array)
            : self::loadHashTable($context, $array);

        return JitArrayCountRecursive::invoke($context, $ht);
    }

    public static function appendElement(Context $context, Value $ht, Variable $element): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nextPtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $index = $context->builder->load($nextPtr);
        HashTableHelper::setAtIndex($context, $ht, $index, $element);
        $one = $context->getTypeFromString('size_t')->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($index, $one),
            $nextPtr
        );
    }

    /**
     * array_push($stack, ...$values) when JIT merges call-time unpack into one packed list (#1361, #4721).
     *
     * php-src: ext/standard/array.c — zero-length spread is a no-op; stack is argument #1.
     */
    public static function pushMergedCallUnpack(Context $context, Variable $packed): Value
    {
        $packedPtr = $context->helper->loadValue($packed);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $stackBox = HashTableHelper::readIndexedToValueBox($context, $packedPtr, $zero);
        $stackVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $stackBox->value
        );
        $stackHt = self::loadHashTable($context, $stackVar);
        $count = $context->builder->truncOrBitCast(
            self::getNumElements($context, $packedPtr),
            $sizeT
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($one, $idxSlot);
        $tag = (string) ++self::$copyListEntrySeq;
        $head = BasicBlockHelper::append($context, 'array_push_unpack_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_push_unpack_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_push_unpack_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_push_unpack_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $value = HashTableHelper::readIndexedToValueBox($context, $packedPtr, $idx);
        self::appendElement($context, $stackHt, $value);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        HashTableHelper::storeHashtableInArrayVariable($context, $stackVar, $stackHt);

        return self::getNumElements($context, $stackHt);
    }

    private static function copyValueEntrySlot(Context $context, Value $fromEntry, Value $toEntry): void
    {
        $tag = 'vs'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($fromEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'value_slot_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'value_slot_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'value_slot_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'value_slot_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'value_slot_null_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'value_slot_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, 'value_slot_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $fromEntry);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__string__separate'), $str)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'value_slot_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readLong'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'value_slot_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $nullBlock);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readLong'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $toEntry);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * array_map() with null or compile-time string builtin callback (subset of PHP).
     */
    public static function buildMapArray(Context $context, Variable $callback, Variable $array): Value
    {
        if (Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            if (self::isNativeArray($array->type)) {
                return self::buildMapNullFromNativeArray($context, $array);
            }

            return self::buildMapNullFromHashTable($context, self::loadHashTable($context, $array));
        }

        $handler = self::resolveMapCallback($callback);
        if (self::isNativeArray($array->type)) {
            return self::buildMapFromNativeArray($context, $handler, $array);
        }

        return self::buildMapFromHashTable($context, $handler, self::loadHashTable($context, $array));
    }

    /**
     * array_map() with closure / arrow callback — delegates to ArrayMapRuntime PHP (#14977).
     */
    public static function buildMapArrayWithClosure(Context $context, Variable $callback, Variable $array): Value
    {
        return ArrayMapRuntime::mapSingle($context, $callback, $array);
    }

    /**
     * array_map() with multiple source arrays — null zip or closure (#4539, ext/standard/array.c).
     *
     * @param list<Variable> $arrays
     */
    public static function buildMapMultipleArrays(Context $context, Variable $callback, array $arrays): Value
    {
        if (Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return self::buildMapNullZipFromMultiple($context, $arrays);
        }
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayMapRuntime::mapMultipleWithClosure($context, $callback, $arrays);
        }
        if (ArrayMapCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        ) && null !== $callback->compileTimeString) {
            return ArrayMapRuntime::mapMultipleWithBuiltin($context, $arrays, $callback->compileTimeString);
        }

        throw new \LogicException(
            'array_map() with multiple arrays requires a null, closure, or compile-time string builtin callback for JIT/AOT in this compiler build'
        );
    }

    /**
     * @param list<Variable> $arrays
     *
     * @return list<Value>
     */
    private static function loadMapSourceHashTables(Context $context, array $arrays): array
    {
        $loaded = [];
        foreach ($arrays as $array) {
            if (self::isNativeArray($array->type)) {
                $loaded[] = self::nativeListToHashTable($context, $array);
            } else {
                $loaded[] = self::loadHashTable($context, $array);
            }
        }

        return $loaded;
    }

    /**
     * @param list<Variable> $arrays
     */
    private static function buildMapNullZipFromMultiple(Context $context, array $arrays): Value
    {
        $sources = self::loadMapSourceHashTables($context, $arrays);
        $first = $sources[0];
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($first, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_nullzip_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_nullzip_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_nullzip_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_nullzip_src');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_nullzip_dest');
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_nullzip_head');
        $check = BasicBlockHelper::append($context, 'array_map_nullzip_check');
        $zipBlock = BasicBlockHelper::append($context, 'array_map_nullzip_zip');
        $skip = BasicBlockHelper::append($context, 'array_map_nullzip_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_nullzip_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $first,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $zipBlock, $skip);

        $context->builder->positionAtEnd($zipBlock);
        $row = self::buildNullZipRowAtIndex($context, $sources, $srcIdx);
        $destIdx = $context->builder->load($destIdxSlot);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $dest,
            $destIdx,
            $row
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    /**
     * @param list<Value> $sources
     */
    private static function buildNullZipRowAtIndex(Context $context, array $sources, Value $index): Value
    {
        $row = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $colIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_nullzip_col');
        $context->builder->store($sizeT->constInt(0, false), $colIdxSlot);
        foreach ($sources as $src) {
            $tag = (string) ++self::$copyListEntrySeq;
            $colIdx = $context->builder->load($colIdxSlot);
            $isSet = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $src,
                $index
            );
            $setBlock = BasicBlockHelper::append($context, 'array_map_nullzip_row_set_'.$tag);
            $nullBlock = BasicBlockHelper::append($context, 'array_map_nullzip_row_null_'.$tag);
            $after = BasicBlockHelper::append($context, 'array_map_nullzip_row_after_'.$tag);
            $context->builder->branchIf($isSet, $setBlock, $nullBlock);

            $context->builder->positionAtEnd($setBlock);
            $elem = HashTableHelper::readIndexedToValueBox($context, $src, $index);
            self::storeValueEntryAtIndexWithHashtable(
                $context,
                $row,
                $colIdx,
                JitValueBox::valuePtrFromVariable($context, $elem)
            );
            $context->builder->branch($after);

            $context->builder->positionAtEnd($nullBlock);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setNullAt'),
                $row,
                $colIdx
            );
            $context->builder->branch($after);

            $context->builder->positionAtEnd($after);
            $context->builder->store(
                $context->builder->addNoSignedWrap($colIdx, $sizeT->constInt(1, false)),
                $colIdxSlot
            );
        }

        return $row;
    }

    /**
     * array_reduce() with compile-time string user-function callbacks (issue #1213).
     */
    public static function buildReduceArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $initial
    ): Value {
        if ($callback->isNullConstant) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (!ArrayReduceCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        $proxy = self::resolveReduceCallback($context, $callback);
        if ($proxy instanceof ExternalMethod) {
            throw new \TypeError(
                ArrayReduceCallbackPolicy::invalidStringCallbackTypeError($callback->compileTimeString ?? '')
            );
        }

        return self::buildReduceFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $proxy,
            $callback->compileTimeString ?? '',
            $initial
        );
    }

    /**
     * array_reduce() with closure / arrow callback — delegates to ArrayReduceRuntime PHP (#14979).
     */
    public static function buildReduceArrayWithClosure(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $initial
    ): Value {
        return ArrayReduceRuntime::reduce($context, $array, $callback, $initial);
    }

    public static function resolveMapCallbackForFind(Variable $callback): Internal
    {
        return self::resolveMapCallback($callback);
    }

    public static function resolveReduceCallbackForFind(Context $context, Variable $callback): Call
    {
        return self::resolveReduceCallback($context, $callback);
    }

    private static function resolveReduceCallback(Context $context, Variable $callback): Call
    {
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidStringCallbackTypeError($name));
        }

        return $context->resolveFunctionProxy($name);
    }

    private static function storeReduceCarryFromCallResult(
        Context $context,
        Value $carrySlot,
        Value $folded,
        string $callbackName,
        ?string $returnTypeTagOverride = null
    ): void {
        $retTy = $returnTypeTagOverride ?? ($context->functionReturnType[strtolower($callbackName)] ?? '__value__');
        if ('int64' === $retTy) {
            JitValueBox::writeLong($context, $carrySlot, $folded);

            return;
        }
        if ('double' === $retTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $carrySlot),
                $folded
            );

            return;
        }
        if ('bool' === $retTy) {
            JitValueBox::writeBool($context, $carrySlot, $folded);

            return;
        }
        JitValueBox::copyFromPointer(
            $context,
            $carrySlot,
            JitValueBox::normalizeValuePtr($context, $folded)
        );
    }

    private static function buildReduceFromHashTable(
        Context $context,
        Value $src,
        Call $proxy,
        string $callbackName,
        ?Variable $initial
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_reduce_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_reduce_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_reduce_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $carrySlot = JitValueBox::alloc($context);
        $carryPtr = JitValueBox::pointer($context, $carrySlot);

        $context->builder->positionAtEnd($emptyBlock);
        if (null !== $initial) {
            JitValueBox::copyFromPointer(
                $context,
                $carrySlot,
                JitValueBox::valuePtrFromVariable($context, $initial)
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $carrySlot)
            );
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        if (null !== $initial) {
            JitValueBox::copyFromPointer(
                $context,
                $carrySlot,
                JitValueBox::valuePtrFromVariable($context, $initial)
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $carrySlot)
            );
        }
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_reduce_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_reduce_head');
        $check = BasicBlockHelper::append($context, 'array_reduce_check');
        $reduceBlock = BasicBlockHelper::append($context, 'array_reduce_reduce');
        $skip = BasicBlockHelper::append($context, 'array_reduce_skip');
        $advance = BasicBlockHelper::append($context, 'array_reduce_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $reduceBlock, $skip);

        $context->builder->positionAtEnd($reduceBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $idx);
        $carryVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $carryPtr);
        $folded = $proxy->call($context, $carryVar, $elem);
        self::storeReduceCarryFromCallResult($context, $carrySlot, $folded, $callbackName);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $resultSlot, $carryPtr);

        return JitValueBox::pointer($context, $resultSlot);
    }

    /** @var array<class-string, int> */
    private const MAP_CALLBACK_RESULT_TYPE = [
        // String keys avoid ::class const fetch during gen-0 bootstrap compile (#1492).
        'PHPCompiler\\ext\\standard\\strval' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\intval' => Variable::TYPE_NATIVE_LONG,
        'PHPCompiler\\ext\\standard\\floatval' => Variable::TYPE_NATIVE_DOUBLE,
        'PHPCompiler\\ext\\standard\\doubleval' => Variable::TYPE_NATIVE_DOUBLE,
        'PHPCompiler\\ext\\standard\\boolval' => Variable::TYPE_NATIVE_BOOL,
        'PHPCompiler\\ext\\standard\\strtolower' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\strtoupper' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_trim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_ltrim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\standard\\string_rtrim' => Variable::TYPE_STRING,
        'PHPCompiler\\ext\\types\\strlen' => Variable::TYPE_NATIVE_LONG,
    ];

    private static function resolveMapCallback(Variable $callback): Internal
    {
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        return VmInternalCall::resolveStringCallback($name);
    }

    private static function mapCallbackResultType(Internal $handler): int
    {
        $type = self::MAP_CALLBACK_RESULT_TYPE[$handler::class] ?? null;
        if (null === $type) {
            throw new \LogicException(
                'array_map() callback is not supported by the JIT compiler in this build'
            );
        }

        return $type;
    }

    private static function buildMapNullFromHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_null_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_null_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_null_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_null_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_null_head');
        $check = BasicBlockHelper::append($context, 'array_map_null_check');
        $copyBlock = BasicBlockHelper::append($context, 'array_map_null_copy');
        $skip = BasicBlockHelper::append($context, 'array_map_null_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_null_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $copyBlock, $skip);

        $context->builder->positionAtEnd($copyBlock);
        self::copyMapNullListEntry($context, $src, $srcIdx, $dest, $srcIdx);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function copyPackedListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        self::copyPackedValueEntry(
            $context,
            self::listEntryAt($context, $src, $srcIndex),
            $dest,
            $destIndex
        );
    }

    /**
     * Store a __value__ list entry into a packed hashtable (enum case/object aware; #5597).
     */
    private static function copyPackedValueEntry(
        Context $context,
        Value $srcEntry,
        Value $dest,
        Value $destIndex
    ): void {
        $tag = 'pv'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'ht_copy_packed_str_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'ht_copy_packed_ht_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_copy_packed_obj_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_copy_packed_long_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_copy_packed_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $afterString = BasicBlockHelper::append($context, 'ht_copy_packed_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $afterHt = BasicBlockHelper::append($context, 'ht_copy_packed_after_ht_'.$tag);
        $context->builder->branchIf($isHt, $htBlock, $afterHt);

        $context->builder->positionAtEnd($htBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHt);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT, false)
        );
        $checkEnumCase = BasicBlockHelper::append($context, 'ht_copy_packed_check_enum_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $checkEnumCase);

        $context->builder->positionAtEnd($checkEnumCase);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $objectBlock, $longBlock);

        $context->builder->positionAtEnd($objectBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readObject'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Identity copy for array_map(null, …): long and string elements only (AOT-safe linker subset).
     */
    private static function copyMapNullListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        self::copyPackedListEntry($context, $src, $srcIndex, $dest, $destIndex);
    }

    private static function buildMapNullFromNativeArray(Context $context, Variable $array): Value
    {
        return self::buildMapNullFromHashTable($context, self::nativeListToHashTable($context, $array));
    }

    private static function buildMapFromHashTable(Context $context, Internal $handler, Value $src): Value
    {
        $resultType = self::mapCallbackResultType($handler);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_head');
        $check = BasicBlockHelper::append($context, 'array_map_check');
        $mapBlock = BasicBlockHelper::append($context, 'array_map_map');
        $skip = BasicBlockHelper::append($context, 'array_map_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $mapBlock, $skip);

        $context->builder->positionAtEnd($mapBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $mapped = $handler->call($context, $elem);
        self::storeMappedAtIndex(
            $context,
            $dest,
            $srcIdx,
            new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
            $resultType
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildMapFromNativeArray(Context $context, Internal $handler, Variable $array): Value
    {
        $resultType = self::mapCallbackResultType($handler);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_map_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_native_head');
        $body = BasicBlockHelper::append($context, 'array_map_native_body');
        $advance = BasicBlockHelper::append($context, 'array_map_native_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        if (Variable::TYPE_STRING === $elemType) {
            $elem = new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        } else {
            $elem = new Variable(
                $context,
                $elemType,
                Variable::KIND_VALUE,
                $context->builder->load($slot)
            );
        }
        $mapped = $handler->call($context, $elem);
        self::storeMappedAtIndex(
            $context,
            $dest,
            $idx,
            new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
            $resultType
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function storeMappedAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Variable $element,
        int $resultType
    ): void {
        switch ($resultType) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setBoolAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setDoubleAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            default:
                throw new \LogicException(
                    'array_map() mapped value type not supported for JIT: '
                    .Variable::getStringType($resultType)
                );
        }
    }

    /**
     * Copy a zero-based native list array into a packed hashtable (indices 0..n-1).
     */
    public static function nativeListToHashTable(Context $context, Variable $array): Value
    {
        $dest = HashTableHelper::alloc($context);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_flip_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_flip_native_head');
        $body = BasicBlockHelper::append($context, 'array_flip_native_body');
        $advance = BasicBlockHelper::append($context, 'array_flip_native_advance');
        $done = BasicBlockHelper::append($context, 'array_flip_native_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        if (Variable::TYPE_STRING === $elemType) {
            $elem = new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        } else {
            $elem = new Variable(
                $context,
                $elemType,
                Variable::KIND_VALUE,
                $context->builder->load($slot)
            );
        }
        HashTableHelper::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        BasicBlockHelper::branchToFreshContinue($context, 'array_flip_native_continue');

        return $dest;
    }

    /**
     * array_count_values() for packed lists of string or integer values (subset of PHP; #2356).
     */
    public static function arrayCountValues(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::countValuesHashTable($context, self::nativeListToHashTable($context, $array));
        }

        return self::countValuesHashTable($context, self::loadHashTable($context, $array));
    }

    private static function countValuesHashTable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_count_values_idx');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_count_values_head');
        $body = BasicBlockHelper::append($context, 'array_count_values_body');
        $count = BasicBlockHelper::append($context, 'array_count_values_count');
        $next = BasicBlockHelper::append($context, 'array_count_values_next');
        $done = BasicBlockHelper::append($context, 'array_count_values_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $count, $next);

        $context->builder->positionAtEnd($count);
        $valEntry = self::listEntryAt($context, $src, $idx);
        self::countIncrementPackedEntry($context, $dest, $valEntry);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        BasicBlockHelper::branchToFreshContinue($context, 'array_count_values_continue');

        return $dest;
    }

    /**
     * Increment occurrence count for one packed input value in the result hashtable.
     */
    private static function countIncrementPackedEntry(Context $context, Value $dest, Value $valEntry): void
    {
        static $serial = 0;
        $id = (string) (++$serial);

        $valueMap = $context->structFieldMap['__value__'];
        $valType = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneLong = $i64->constInt(1, false);

        $stringBlock = BasicBlockHelper::append($context, 'array_count_values_val_string_'.$id);
        $longBlock = BasicBlockHelper::append($context, 'array_count_values_val_long_'.$id);
        $done = BasicBlockHelper::append($context, 'array_count_values_val_done_'.$id);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_count_values_after_string_'.$id);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $exists = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $ownedKey
        );
        $strNew = BasicBlockHelper::append($context, 'array_count_values_str_new_'.$id);
        $strInc = BasicBlockHelper::append($context, 'array_count_values_str_inc_'.$id);
        $strDone = BasicBlockHelper::append($context, 'array_count_values_str_done_'.$id);
        $context->builder->branchIf($exists, $strInc, $strNew);

        $context->builder->positionAtEnd($strNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $oneLong
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strInc);
        $existing = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $ownedKey
        );
        $oldCount = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $existing
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $context->builder->addNoSignedWrap($oldCount, $oneLong)
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
        $context->builder->branch($done);

        $skipBlock = BasicBlockHelper::append($context, 'array_count_values_val_skip_'.$id);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $skipBlock);

        $context->builder->positionAtEnd($skipBlock);
        self::emitCountValuesSkipWarning($context);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $keyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
            $sizeT
        );
        $existsIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $keyIdx
        );
        $longNew = BasicBlockHelper::append($context, 'array_count_values_long_new_'.$id);
        $longInc = BasicBlockHelper::append($context, 'array_count_values_long_inc_'.$id);
        $longDone = BasicBlockHelper::append($context, 'array_count_values_long_done_'.$id);
        $context->builder->branchIf($existsIdx, $longInc, $longNew);

        $context->builder->positionAtEnd($longNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $oneLong
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longInc);
        $oldCountIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $dest,
            $keyIdx
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $context->builder->addNoSignedWrap($oldCountIdx, $oneLong)
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function emitCountValuesSkipWarning(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast(
            $context->constantFromString(
                'array_count_values(): Can only count string and integer values, entry skipped'
            ),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(2, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    private static function emitBuiltinWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast(
            $context->constantFromString($message),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(2, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    /**
     * Copy a sub-range of a packed list array (array_slice subset; matches VM HashTable::sliceCopy).
     *
     * @param Value $offset   int64 slice offset (negative offsets normalized against element count)
     * @param Value $hasLength int1 true when the optional length argument was provided
     * @param Value $length    int64 maximum elements to copy (ignored when $hasLength is false)
     */
    public static function buildSliceArray(
        Context $context,
        Variable $array,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?Value $preserveKeys = null
    ): Value {
        if (null === $preserveKeys) {
            $src = self::isNativeArray($array->type)
                ? self::nativeListToHashTable($context, $array)
                : self::loadHashTable($context, $array);

            return self::buildSliceFromHashTable(
                $context,
                $src,
                $offset,
                $hasLength,
                $length
            );
        }

        $reindexBlock = BasicBlockHelper::append($context, 'array_slice_reindex');
        $preserveBlock = BasicBlockHelper::append($context, 'array_slice_preserve');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_preserve_branch_done');
        $context->builder->branchIf($preserveKeys, $preserveBlock, $reindexBlock);

        $context->builder->positionAtEnd($reindexBlock);
        $reindexResult = self::buildSliceArray($context, $array, $offset, $hasLength, $length, null);
        $reindexEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($preserveBlock);
        $preserveResult = self::buildSliceArrayPreserveKeys($context, $array, $offset, $hasLength, $length);
        $preserveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($reindexResult->typeOf());
        $phi->addIncoming($reindexResult, $reindexEnd);
        $phi->addIncoming($preserveResult, $preserveEnd);

        return $phi;
    }

    private static function buildSliceArrayPreserveKeys(
        Context $context,
        Variable $array,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        $src = self::isNativeArray($array->type)
            ? self::nativeListToHashTable($context, $array)
            : self::loadHashTable($context, $array);

        return self::buildSlicePreserveKeysFromHashTable(
            $context,
            $src,
            $offset,
            $hasLength,
            $length
        );
    }

    /**
     * List spread tail for keyed destructuring — VM HashTable::copyListSpreadTail (#4889, #4979).
     *
     * @param list<string> $excludedStringKeys compile-time string keys already bound before spread
     */
    public static function buildCopyListSpreadTail(
        Context $context,
        Variable $array,
        Value $offset,
        array $excludedStringKeys
    ): Value {
        if (self::isNativeArray($array->type)) {
            $src = self::nativeListToHashTable($context, $array);
        } else {
            $src = self::loadHashTable($context, $array);
        }

        return self::buildCopyListSpreadTailFromHashTable(
            $context,
            $src,
            $offset,
            $excludedStringKeys
        );
    }

    /**
     * @param list<string> $excludedStringKeys
     */
    private static function buildCopyListSpreadTailFromHashTable(
        Context $context,
        Value $src,
        Value $offset,
        array $excludedStringKeys
    ): Value {
        $tag = 'lst'.(string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $normOffset = $context->builder->truncOrBitCast($offset, $sizeT);

        $dest = HashTableHelper::alloc($context);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $idxSlot = $context->builder->alloca($sizeT, 1, 'list_spread_tail_idx_'.$tag);
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'list_spread_tail_packed_head_'.$tag);
        $packedBody = BasicBlockHelper::append($context, 'list_spread_tail_packed_body_'.$tag);
        $packedCopy = BasicBlockHelper::append($context, 'list_spread_tail_packed_copy_'.$tag);
        $packedSkip = BasicBlockHelper::append($context, 'list_spread_tail_packed_skip_'.$tag);
        $packedNext = BasicBlockHelper::append($context, 'list_spread_tail_packed_next_'.$tag);
        $packedDone = BasicBlockHelper::append($context, 'list_spread_tail_packed_done_'.$tag);
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCopy, $packedSkip);

        $context->builder->positionAtEnd($packedCopy);
        $idx = $context->builder->load($idxSlot);
        $belowOffset = $context->builder->icmp(Builder::INT_SLT, $idx, $normOffset);
        $context->builder->branchIf($belowOffset, $packedSkip, $packedNext);

        $context->builder->positionAtEnd($packedNext);
        $idx = $context->builder->load($idxSlot);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $idx,
            self::listEntryAt($context, $src, $idx)
        );
        $context->builder->branch($packedSkip);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($idxSlot), $one),
            $idxSlot
        );
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'list_spread_tail_str_init_'.$tag);
        $strHead = BasicBlockHelper::append($context, 'list_spread_tail_str_head_'.$tag);
        $strBody = BasicBlockHelper::append($context, 'list_spread_tail_str_body_'.$tag);
        $strNext = BasicBlockHelper::append($context, 'list_spread_tail_str_next_'.$tag);
        $strDone = BasicBlockHelper::append($context, 'list_spread_tail_str_done_'.$tag);

        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'list_spread_tail_walk_'.$tag);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $isExcluded = self::isListSpreadExcludedStringKey($context, $keyStr, $excludedStringKeys);
        $strCopy = BasicBlockHelper::append($context, 'list_spread_tail_str_copy_'.$tag);
        $context->builder->branchIf($isExcluded, $strNext, $strCopy);

        $context->builder->positionAtEnd($strCopy);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $ownedKey = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        self::storeValueEntryAtStringKey($context, $dest, $ownedKey, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<string> $excludedStringKeys
     */
    private static function isListSpreadExcludedStringKey(
        Context $context,
        Value $keyStr,
        array $excludedStringKeys
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        if ([] === $excludedStringKeys) {
            return $i1->constInt(0, false);
        }
        $strMap = $context->structFieldMap['__string__'];
        $keyData = $context->builder->structGep($keyStr, $strMap['value']);
        $excluded = $i1->constInt(0, false);
        foreach ($excludedStringKeys as $excl) {
            $exclPtr = $context->builder->pointerCast(
                $context->constantFromString($excl),
                $context->getTypeFromString('int8*')
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $keyData,
                $exclPtr
            );
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
            $excluded = $context->builder->or($excluded, $match);
        }

        return $excluded;
    }

    private static function normalizeSliceOffset(Context $context, Value $offset, Value $count): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $offset, $zero);

        $negBlock = BasicBlockHelper::append($context, 'array_slice_offset_neg');
        $posBlock = BasicBlockHelper::append($context, 'array_slice_offset_pos');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_offset_done');
        $context->builder->branchIf($isNegative, $negBlock, $posBlock);

        $context->builder->positionAtEnd($negBlock);
        $adjusted = $context->builder->add($count, $offset);
        $stillNegative = $context->builder->icmp(Builder::INT_SLT, $adjusted, $zero);
        $normalizedNeg = $context->builder->select($stillNegative, $zero, $adjusted);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($normalizedNeg, $negBlock);
        $phi->addIncoming($offset, $posBlock);

        return $phi;
    }


    private static function buildSliceFromHashTable(
        Context $context,
        Value $src,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $countI64 = $context->builder->zExt($num, $i64);
        $normOffsetI64 = self::normalizeSliceOffset($context, $offset, $countI64);
        $normOffset = $context->builder->truncOrBitCast($normOffsetI64, $sizeT);
        $lengthSized = $context->builder->truncOrBitCast($length, $sizeT);

        $emptyHt = HashTableHelper::alloc($context);
        $beyondEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $nextFree);
        $emptyBlock = BasicBlockHelper::append($context, 'array_slice_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_slice_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_done');
        $context->builder->branchIf($beyondEnd, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_src');
        $logicalIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_logical');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_dest');
        $takenSlot = $context->builder->alloca($sizeT, 1, 'array_slice_taken');
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $logicalIdxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $context->builder->store($zero, $takenSlot);

        $head = BasicBlockHelper::append($context, 'array_slice_head');
        $check = BasicBlockHelper::append($context, 'array_slice_check');
        $skipUnset = BasicBlockHelper::append($context, 'array_slice_skip_unset');
        $beforeOffset = BasicBlockHelper::append($context, 'array_slice_before_offset');
        $limitExit = BasicBlockHelper::append($context, 'array_slice_limit_exit');
        $limitDone = BasicBlockHelper::append($context, 'array_slice_limit_done');
        $copyBlock = BasicBlockHelper::append($context, 'array_slice_copy');
        $advanceLogical = BasicBlockHelper::append($context, 'array_slice_advance_logical');
        $advanceSrc = BasicBlockHelper::append($context, 'array_slice_advance_src');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $beforeOffset, $skipUnset);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advanceSrc);

        $context->builder->positionAtEnd($beforeOffset);
        $logicalIdx = $context->builder->load($logicalIdxSlot);
        $beforeSlice = $context->builder->icmp(Builder::INT_SLT, $logicalIdx, $normOffset);
        $context->builder->branchIf($beforeSlice, $advanceLogical, $copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $taken = $context->builder->load($takenSlot);
        $limitReached = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SGE, $taken, $lengthSized)
        );
        $context->builder->branchIf($limitReached, $limitExit, $limitDone);

        $context->builder->positionAtEnd($limitExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($limitDone);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $destIdx = $context->builder->load($destIdxSlot);
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $context->builder->load($takenSlot),
                $one
            ),
            $takenSlot
        );
        $context->builder->branch($advanceLogical);

        $context->builder->positionAtEnd($advanceLogical);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($logicalIdxSlot), $one),
            $logicalIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advanceSrc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);
        $phi->addIncoming($dest, $limitExit);

        return $phi;
    }


    private static function buildSlicePreserveKeysFromHashTable(
        Context $context,
        Value $src,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        $tag = 'aspk'.(string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $countI64 = $context->builder->zExt($num, $i64);
        $normOffsetI64 = self::normalizeSliceOffset($context, $offset, $countI64);
        $normOffset = $context->builder->truncOrBitCast($normOffsetI64, $sizeT);
        $lengthSized = $context->builder->truncOrBitCast($length, $sizeT);

        $emptyHt = HashTableHelper::alloc($context);
        $beyondEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $num);
        $emptyBlock = BasicBlockHelper::append($context, 'array_slice_pk_empty_'.$tag);
        $workBlock = BasicBlockHelper::append($context, 'array_slice_pk_work_'.$tag);
        $packedDoneBlock = BasicBlockHelper::append($context, 'array_slice_pk_packed_done_'.$tag);
        $finalDoneBlock = BasicBlockHelper::append($context, 'array_slice_pk_final_done_'.$tag);
        $context->builder->branchIf($beyondEnd, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($finalDoneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_pk_src_'.$tag);
        $logicalIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_pk_logical_'.$tag);
        $takenSlot = $context->builder->alloca($sizeT, 1, 'array_slice_pk_taken_'.$tag);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_slice_pk_walk_'.$tag);
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $logicalIdxSlot);
        $context->builder->store($zero, $takenSlot);

        $head = BasicBlockHelper::append($context, 'array_slice_pk_head_'.$tag);
        $check = BasicBlockHelper::append($context, 'array_slice_pk_check_'.$tag);
        $skipUnset = BasicBlockHelper::append($context, 'array_slice_pk_skip_unset_'.$tag);
        $beforeOffset = BasicBlockHelper::append($context, 'array_slice_pk_before_offset_'.$tag);
        $limitExit = BasicBlockHelper::append($context, 'array_slice_pk_limit_exit_'.$tag);
        $limitDone = BasicBlockHelper::append($context, 'array_slice_pk_limit_done_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_slice_pk_copy_'.$tag);
        $advanceLogical = BasicBlockHelper::append($context, 'array_slice_pk_advance_logical_'.$tag);
        $advanceSrc = BasicBlockHelper::append($context, 'array_slice_pk_advance_src_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDoneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $beforeOffset, $skipUnset);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advanceSrc);

        $context->builder->positionAtEnd($beforeOffset);
        $logicalIdx = $context->builder->load($logicalIdxSlot);
        $beforeSlice = $context->builder->icmp(Builder::INT_SLT, $logicalIdx, $normOffset);
        $context->builder->branchIf($beforeSlice, $advanceLogical, $copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $taken = $context->builder->load($takenSlot);
        $limitReached = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SGE, $taken, $lengthSized)
        );
        $context->builder->branchIf($limitReached, $limitExit, $limitDone);

        $context->builder->positionAtEnd($limitExit);
        $context->builder->branch($finalDoneBlock);

        $context->builder->positionAtEnd($limitDone);
        $srcIdx = $context->builder->load($srcIdxSlot);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $srcIdx,
            self::listEntryAt($context, $src, $srcIdx)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $context->builder->load($takenSlot),
                $one
            ),
            $takenSlot
        );
        $context->builder->branch($advanceLogical);

        $context->builder->positionAtEnd($advanceLogical);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($logicalIdxSlot), $one),
            $logicalIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advanceSrc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $strInit = BasicBlockHelper::append($context, 'array_slice_pk_str_init_'.$tag);
        $strHead = BasicBlockHelper::append($context, 'array_slice_pk_str_head_'.$tag);
        $strBody = BasicBlockHelper::append($context, 'array_slice_pk_str_body_'.$tag);
        $strBefore = BasicBlockHelper::append($context, 'array_slice_pk_str_before_'.$tag);
        $strLimitExit = BasicBlockHelper::append($context, 'array_slice_pk_str_limit_exit_'.$tag);
        $strLimitDone = BasicBlockHelper::append($context, 'array_slice_pk_str_limit_done_'.$tag);
        $strCopy = BasicBlockHelper::append($context, 'array_slice_pk_str_copy_'.$tag);
        $strAdvance = BasicBlockHelper::append($context, 'array_slice_pk_str_advance_'.$tag);
        $strNext = BasicBlockHelper::append($context, 'array_slice_pk_str_next_'.$tag);

        $context->builder->positionAtEnd($packedDoneBlock);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $finalDoneBlock, $strBody);

        $context->builder->positionAtEnd($strBody);
        $context->builder->branch($strBefore);

        $context->builder->positionAtEnd($strBefore);
        $logicalIdx = $context->builder->load($logicalIdxSlot);
        $beforeSlice = $context->builder->icmp(Builder::INT_SLT, $logicalIdx, $normOffset);
        $context->builder->branchIf($beforeSlice, $strAdvance, $strCopy);

        $context->builder->positionAtEnd($strCopy);
        $taken = $context->builder->load($takenSlot);
        $limitReached = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SGE, $taken, $lengthSized)
        );
        $context->builder->branchIf($limitReached, $strLimitExit, $strLimitDone);

        $context->builder->positionAtEnd($strLimitExit);
        $context->builder->branch($finalDoneBlock);

        $context->builder->positionAtEnd($strLimitDone);
        $node = $context->builder->load($walkSlot);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $context->builder->load($takenSlot),
                $one
            ),
            $takenSlot
        );
        $context->builder->branch($strAdvance);

        $context->builder->positionAtEnd($strAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($logicalIdxSlot), $one),
            $logicalIdxSlot
        );
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $node = $context->builder->load($walkSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($finalDoneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $limitExit);
        $phi->addIncoming($dest, $strLimitExit);
        $phi->addIncoming($dest, $strHead);

        return $phi;
    }

    private static function storeMappedVariableToValuePtr(
        Context $context,
        Value $valPtr,
        Variable $mapped,
        int $resultType
    ): void {
        switch ($resultType) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $valPtr,
                    $context->helper->loadValue($mapped)
                );
                break;
            case Variable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    JitStringArg::stringPtrFromVariable($context, $mapped)
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $valPtr,
                    $owned
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $valPtr,
                    $context->builder->truncOrBitCast(
                        $context->helper->loadValue($mapped),
                        $context->getTypeFromString('int1')
                    )
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $valPtr,
                    $context->helper->loadValue($mapped)
                );
                break;
            default:
                throw new \LogicException(
                    'array_walk_recursive() mapped value type not supported for JIT: '
                    .Variable::getStringType($resultType)
                );
        }
    }


    /**
     * array_combine() length guard — ValueError when counts differ (php-src ext/standard/array.c; #16080).
     *
     * On success, positions the builder at {@see BasicBlockHelper::append()} `{prefix}_work` (or {@param $okSuffix}).
     */
    public static function guardCombinePackedListLengthMismatch(
        Context $context,
        Variable $keys,
        Variable $values,
        string $okSuffix = 'work'
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $keysNum = self::packedListElementCount($context, $keys);
        $valsNum = self::packedListElementCount($context, $values);
        $keysEmpty = $context->builder->icmp(Builder::INT_EQ, $keysNum, $zero);
        $valsEmpty = $context->builder->icmp(Builder::INT_EQ, $valsNum, $zero);
        $bothEmpty = $context->builder->and($keysEmpty, $valsEmpty);
        $eitherEmpty = $context->builder->or($keysEmpty, $valsEmpty);
        $lengthMismatch = $context->builder->icmp(Builder::INT_NE, $keysNum, $valsNum);
        $returnFalse = $context->builder->or(
            $context->builder->and($eitherEmpty, $context->builder->not($bothEmpty)),
            $lengthMismatch
        );

        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($returnFalse),
            'array_combine',
            array_combine::LENGTH_MISMATCH_ERROR,
            $okSuffix,
            'count_mismatch'
        );
    }

    /**
     * Packed list length for array_combine() guards (mirrors ext/standard/array_count.php).
     */
    private static function packedListElementCount(Context $context, Variable $array): Value
    {
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return $context->constantFromInteger($array->nextFreeElement, 'size_t');
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__getNumElements'),
                self::loadHashTable($context, $array)
            );
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__getNumElements'),
                self::loadHashTable($context, $array)
            );
        }

        throw new \LogicException('array_combine() packedListElementCount: unsupported array operand type');
    }

    /**
     * array_fill_keys() — values of {@param $keys} become keys; uniform {@param $value} (returns __value__*).
     */
    public static function fillKeys(Context $context, Variable $keys, Variable $value): Value
    {
        $keysHt = self::isNativeArray($keys->type)
            ? HashTableHelper::materializeNativeArrayForCall($context, $keys)
            : self::loadHashTable($context, $keys);

        return self::fillKeysHashTable($context, $keysHt, $value);
    }

    /**
     * array_pad() — pad a packed list hashtable to abs({@param $length}) with {@param $value}.
     */
    public static function pad(Context $context, Variable $array, Value $length, Variable $value): Value
    {
        $src = self::isNativeArray($array->type)
            ? HashTableHelper::materializeNativeArrayForCall($context, $array)
            : self::loadHashTable($context, $array);

        return self::padHashTable($context, $src, $length, $value);
    }

    /**
     * array_pad() 4-arg form — absolute {@param $length} with explicit ARRAY_PAD_* / ArrayPadType (#14993, #17600).
     */
    public static function padWithType(
        Context $context,
        Variable $array,
        Value $length,
        Variable $value,
        Value $padType
    ): Value {
        $src = self::isNativeArray($array->type)
            ? HashTableHelper::materializeNativeArrayForCall($context, $array)
            : self::loadHashTable($context, $array);

        return self::padHashTableWithType($context, $src, $length, $value, $padType);
    }

    private static function padHashTable(Context $context, Value $src, Value $length, Variable $value): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'array_pad_abs_neg');
        $posBlock = BasicBlockHelper::append($context, 'array_pad_abs_pos');
        $absDone = BasicBlockHelper::append($context, 'array_pad_abs_done');
        $context->builder->branchIf($negLen, $negBlock, $posBlock);
        $context->builder->positionAtEnd($negBlock);
        $negated = $context->builder->sub($zero64, $length);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($absDone);
        $absPhi = $context->builder->phi($i64);
        $absPhi->addIncoming($negated, $negBlock);
        $absPhi->addIncoming($length, $posBlock);
        $target = $context->builder->truncOrBitCast($absPhi, $sizeT);

        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $needsPad = $context->builder->icmp(Builder::INT_SGT, $target, $count);
        $noPadBlock = BasicBlockHelper::append($context, 'array_pad_no_pad');
        $padBlock = BasicBlockHelper::append($context, 'array_pad_pad');
        $doneBlock = BasicBlockHelper::append($context, 'array_pad_done');
        $context->builder->branchIf($needsPad, $padBlock, $noPadBlock);

        $context->builder->positionAtEnd($noPadBlock);
        $copied = self::copyPackedListHashTable($context, $src, $zero);
        $noPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($padBlock);
        $padCount = $context->builder->sub($target, $count);
        $posLen = $context->builder->icmp(Builder::INT_SGT, $length, $zero64);
        $rightBlock = BasicBlockHelper::append($context, 'array_pad_right');
        $leftBlock = BasicBlockHelper::append($context, 'array_pad_left');
        $padDone = BasicBlockHelper::append($context, 'array_pad_pad_done');
        $context->builder->branchIf($posLen, $rightBlock, $leftBlock);

        $context->builder->positionAtEnd($rightBlock);
        $rightHt = self::copyPackedListHashTable($context, $src, $zero);
        $padIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_right_idx');
        $context->builder->store($count, $padIdxSlot);
        $rightHead = BasicBlockHelper::append($context, 'array_pad_right_head');
        $rightBody = BasicBlockHelper::append($context, 'array_pad_right_body');
        $rightAdvance = BasicBlockHelper::append($context, 'array_pad_right_advance');
        $rightDone = BasicBlockHelper::append($context, 'array_pad_right_done');
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightHead);
        $padIdx = $context->builder->load($padIdxSlot);
        $rightAtEnd = $context->builder->icmp(Builder::INT_SGE, $padIdx, $target);
        $context->builder->branchIf($rightAtEnd, $rightDone, $rightBody);
        $context->builder->positionAtEnd($rightBody);
        self::appendElement($context, $rightHt, $value);
        $context->builder->branch($rightAdvance);
        $context->builder->positionAtEnd($rightAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($padIdx, $one),
            $padIdxSlot
        );
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightDone);
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($leftBlock);
        $leftHt = HashTableHelper::alloc($context);
        $leftIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_left_pad_idx');
        $context->builder->store($zero, $leftIdxSlot);
        $leftPadHead = BasicBlockHelper::append($context, 'array_pad_left_pad_head');
        $leftPadBody = BasicBlockHelper::append($context, 'array_pad_left_pad_body');
        $leftPadAdvance = BasicBlockHelper::append($context, 'array_pad_left_pad_advance');
        $leftCopyBlock = BasicBlockHelper::append($context, 'array_pad_left_copy');
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftPadHead);
        $leftPadIdx = $context->builder->load($leftIdxSlot);
        $leftPadAtEnd = $context->builder->icmp(Builder::INT_SGE, $leftPadIdx, $padCount);
        $context->builder->branchIf($leftPadAtEnd, $leftCopyBlock, $leftPadBody);
        $context->builder->positionAtEnd($leftPadBody);
        HashTableHelper::setAtIndex($context, $leftHt, $leftPadIdx, $value);
        $context->builder->branch($leftPadAdvance);
        $context->builder->positionAtEnd($leftPadAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($leftPadIdx, $one),
            $leftIdxSlot
        );
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftCopyBlock);
        $leftPadded = self::copyPackedListHashTable($context, $src, $padCount, $leftHt);
        $leftPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($padDone);
        $padPhi = $context->builder->phi($rightHt->typeOf());
        $padPhi->addIncoming($rightHt, $rightDone);
        $padPhi->addIncoming($leftPadded, $leftPadExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($copied->typeOf());
        $resultPhi->addIncoming($copied, $noPadExit);
        $resultPhi->addIncoming($padPhi, $padDone);

        return $resultPhi;
    }

    /**
     * PHP 8.4+ array_pad() typed padding — {@param $length} is absolute size (#14993).
     */
    private static function padHashTableWithType(
        Context $context,
        Value $src,
        Value $length,
        Variable $value,
        Value $padType
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'array_pad_type_abs_neg');
        $posBlock = BasicBlockHelper::append($context, 'array_pad_type_abs_pos');
        $absDone = BasicBlockHelper::append($context, 'array_pad_type_abs_done');
        $context->builder->branchIf($negLen, $negBlock, $posBlock);
        $context->builder->positionAtEnd($negBlock);
        $negated = $context->builder->sub($zero64, $length);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($absDone);
        $absPhi = $context->builder->phi($i64);
        $absPhi->addIncoming($negated, $negBlock);
        $absPhi->addIncoming($length, $posBlock);
        $target = $context->builder->truncOrBitCast($absPhi, $sizeT);

        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $needsPad = $context->builder->icmp(Builder::INT_SGT, $target, $count);
        $noPadBlock = BasicBlockHelper::append($context, 'array_pad_type_no_pad');
        $padBlock = BasicBlockHelper::append($context, 'array_pad_type_pad');
        $doneBlock = BasicBlockHelper::append($context, 'array_pad_type_done');
        $context->builder->branchIf($needsPad, $padBlock, $noPadBlock);

        $context->builder->positionAtEnd($noPadBlock);
        $copied = self::copyPackedListHashTable($context, $src, $zero);
        $noPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($padBlock);
        $padCount = $context->builder->sub($target, $count);
        $bothConst = $i64->constInt(StdlibConstants::ARRAY_PAD_BOTH, false);
        $leftConst = $i64->constInt(StdlibConstants::ARRAY_PAD_LEFT, false);
        $isBoth = $context->builder->icmp(Builder::INT_EQ, $padType, $bothConst);
        $isLeft = $context->builder->icmp(Builder::INT_EQ, $padType, $leftConst);
        $bothBlock = BasicBlockHelper::append($context, 'array_pad_type_both');
        $leftBlock = BasicBlockHelper::append($context, 'array_pad_type_left');
        $rightBlock = BasicBlockHelper::append($context, 'array_pad_type_right');
        $dispatchBlock = BasicBlockHelper::append($context, 'array_pad_type_dispatch');
        $typeDone = BasicBlockHelper::append($context, 'array_pad_type_mode_done');
        $context->builder->branchIf($isBoth, $bothBlock, $dispatchBlock);
        $context->builder->positionAtEnd($dispatchBlock);
        $context->builder->branchIf($isLeft, $leftBlock, $rightBlock);

        $context->builder->positionAtEnd($rightBlock);
        $rightHt = self::copyPackedListHashTable($context, $src, $zero);
        $padIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_type_right_idx');
        $context->builder->store($count, $padIdxSlot);
        $rightHead = BasicBlockHelper::append($context, 'array_pad_type_right_head');
        $rightBody = BasicBlockHelper::append($context, 'array_pad_type_right_body');
        $rightAdvance = BasicBlockHelper::append($context, 'array_pad_type_right_advance');
        $rightDone = BasicBlockHelper::append($context, 'array_pad_type_right_done');
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightHead);
        $padIdx = $context->builder->load($padIdxSlot);
        $rightAtEnd = $context->builder->icmp(Builder::INT_SGE, $padIdx, $target);
        $context->builder->branchIf($rightAtEnd, $rightDone, $rightBody);
        $context->builder->positionAtEnd($rightBody);
        self::appendElement($context, $rightHt, $value);
        $context->builder->branch($rightAdvance);
        $context->builder->positionAtEnd($rightAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($padIdx, $one),
            $padIdxSlot
        );
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightDone);
        $rightExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($leftBlock);
        $leftHt = HashTableHelper::alloc($context);
        $leftIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_type_left_pad_idx');
        $context->builder->store($zero, $leftIdxSlot);
        $leftPadHead = BasicBlockHelper::append($context, 'array_pad_type_left_pad_head');
        $leftPadBody = BasicBlockHelper::append($context, 'array_pad_type_left_pad_body');
        $leftPadAdvance = BasicBlockHelper::append($context, 'array_pad_type_left_pad_advance');
        $leftCopyBlock = BasicBlockHelper::append($context, 'array_pad_type_left_copy');
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftPadHead);
        $leftPadIdx = $context->builder->load($leftIdxSlot);
        $leftPadAtEnd = $context->builder->icmp(Builder::INT_SGE, $leftPadIdx, $padCount);
        $context->builder->branchIf($leftPadAtEnd, $leftCopyBlock, $leftPadBody);
        $context->builder->positionAtEnd($leftPadBody);
        HashTableHelper::setAtIndex($context, $leftHt, $leftPadIdx, $value);
        $context->builder->branch($leftPadAdvance);
        $context->builder->positionAtEnd($leftPadAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($leftPadIdx, $one),
            $leftIdxSlot
        );
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftCopyBlock);
        $leftPadded = self::copyPackedListHashTable($context, $src, $padCount, $leftHt);
        $leftExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($bothBlock);
        $leftCount = $context->builder->unsignedDiv(
            $context->builder->add($padCount, $one),
            $two
        );
        $rightCount = $context->builder->sub($padCount, $leftCount);
        $bothHt = HashTableHelper::alloc($context);
        $bothLeftIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_type_both_left_idx');
        $context->builder->store($zero, $bothLeftIdxSlot);
        $bothLeftHead = BasicBlockHelper::append($context, 'array_pad_type_both_left_head');
        $bothLeftBody = BasicBlockHelper::append($context, 'array_pad_type_both_left_body');
        $bothLeftAdvance = BasicBlockHelper::append($context, 'array_pad_type_both_left_advance');
        $bothCopyBlock = BasicBlockHelper::append($context, 'array_pad_type_both_copy');
        $context->builder->branch($bothLeftHead);
        $context->builder->positionAtEnd($bothLeftHead);
        $bothLeftIdx = $context->builder->load($bothLeftIdxSlot);
        $bothLeftAtEnd = $context->builder->icmp(Builder::INT_SGE, $bothLeftIdx, $leftCount);
        $context->builder->branchIf($bothLeftAtEnd, $bothCopyBlock, $bothLeftBody);
        $context->builder->positionAtEnd($bothLeftBody);
        HashTableHelper::setAtIndex($context, $bothHt, $bothLeftIdx, $value);
        $context->builder->branch($bothLeftAdvance);
        $context->builder->positionAtEnd($bothLeftAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($bothLeftIdx, $one),
            $bothLeftIdxSlot
        );
        $context->builder->branch($bothLeftHead);
        $context->builder->positionAtEnd($bothCopyBlock);
        $bothWithSrc = self::copyPackedListHashTable($context, $src, $leftCount, $bothHt);
        $bothRightIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_type_both_right_idx');
        $context->builder->store($zero, $bothRightIdxSlot);
        $bothRightHead = BasicBlockHelper::append($context, 'array_pad_type_both_right_head');
        $bothRightBody = BasicBlockHelper::append($context, 'array_pad_type_both_right_body');
        $bothRightAdvance = BasicBlockHelper::append($context, 'array_pad_type_both_right_advance');
        $bothRightDone = BasicBlockHelper::append($context, 'array_pad_type_both_right_done');
        $context->builder->branch($bothRightHead);
        $context->builder->positionAtEnd($bothRightHead);
        $bothRightIdx = $context->builder->load($bothRightIdxSlot);
        $bothRightAtEnd = $context->builder->icmp(Builder::INT_SGE, $bothRightIdx, $rightCount);
        $context->builder->branchIf($bothRightAtEnd, $bothRightDone, $bothRightBody);
        $context->builder->positionAtEnd($bothRightBody);
        self::appendElement($context, $bothWithSrc, $value);
        $context->builder->branch($bothRightAdvance);
        $context->builder->positionAtEnd($bothRightAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($bothRightIdx, $one),
            $bothRightIdxSlot
        );
        $context->builder->branch($bothRightHead);
        $context->builder->positionAtEnd($bothRightDone);
        $bothExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($typeDone);
        $typePhi = $context->builder->phi($rightHt->typeOf());
        $typePhi->addIncoming($rightHt, $rightExit);
        $typePhi->addIncoming($leftPadded, $leftExit);
        $typePhi->addIncoming($bothWithSrc, $bothExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($copied->typeOf());
        $resultPhi->addIncoming($copied, $noPadExit);
        $resultPhi->addIncoming($typePhi, $typeDone);

        return $resultPhi;
    }

    /**
     * Copy packed list {@param $src} into a new hashtable starting at {@param $destOffset}.
     */
    private static function copyPackedListHashTable(
        Context $context,
        Value $src,
        Value $destOffset,
        ?Value $destIn = null
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_pad_copy_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_pad_copy_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_pad_copy_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = null !== $destIn ? $destIn : HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = null !== $destIn ? $destIn : HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_copy_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_pad_copy_head');
        $body = BasicBlockHelper::append($context, 'array_pad_copy_body');
        $advance = BasicBlockHelper::append($context, 'array_pad_copy_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $destIdx = $context->builder->addNoSignedWrap($destOffset, $srcIdx);
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function fillKeysHashTable(Context $context, Value $keysHt, Variable $value): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $keysNum = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $keysHt
        );
        $dest = HashTableHelper::alloc($context);
        $valEntry = self::valueEntryPtrForFill($context, $value);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_fill_keys_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_fill_keys_head');
        $body = BasicBlockHelper::append($context, 'array_fill_keys_body');
        $advance = BasicBlockHelper::append($context, 'array_fill_keys_advance');
        $loopDone = BasicBlockHelper::append($context, 'array_fill_keys_loop_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $keysNum);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $keyEntry = self::listEntryAt($context, $keysHt, $idx);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valEntry);
        self::storeCombinedEntry(
            $context,
            $dest,
            $keyEntry,
            JitValueBox::pointer($context, $valSlot)
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $dest
        );

        return $resultPtr;
    }

    private static function valueEntryPtrForFill(Context $context, Variable $value): Value
    {
        if (Variable::TYPE_VALUE === $value->type) {
            return JitValueBox::valuePtrFromVariable($context, $value);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($value));
                break;
            case Variable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->builder->truncOrBitCast($context->helper->loadValue($value), $context->getTypeFromString('int1'))
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->helper->loadValue($value)
                );
                break;
            case Variable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                $strMap = $context->structFieldMap['__string__'];
                $hayPtr = $context->builder->structGep($str, $strMap['value']);
                $len = $context->builder->load(
                    $context->builder->structGep($str, $strMap['length'])
                );
                $owned = string_trim::jitCopySlice(
                    $context,
                    $str,
                    $hayPtr,
                    $context->getTypeFromString('int64')->constInt(0, false),
                    $len,
                    'fill_keys_val'
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    'array_fill_keys() value type not supported for JIT: '
                    .Variable::getStringType($value->type)
                );
        }

        return $ptr;
    }

    private static function flipStoreValueAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Value $keyEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $keyType = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'array_flip_val_string');
        $longBlock = BasicBlockHelper::append($context, 'array_flip_val_long');
        $done = BasicBlockHelper::append($context, 'array_flip_val_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_val_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readString'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function flipStoreValueAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $keyEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $keyType = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'array_flip_sval_string');
        $longBlock = BasicBlockHelper::append($context, 'array_flip_sval_long');
        $done = BasicBlockHelper::append($context, 'array_flip_sval_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_sval_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readString'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function storeCombinedEntry(
        Context $context,
        Value $dest,
        Value $keyEntry,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');

        $enumErrorBlock = BasicBlockHelper::append($context, 'array_combine_key_enum_error');
        $afterEnumCheck = BasicBlockHelper::append($context, 'array_combine_after_enum_check');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumErrorBlock, $afterEnumCheck);

        $context->builder->positionAtEnd($enumErrorBlock);
        ErrorRaise::ensureLinked($context);
        $context->type->object->emitEnumCaseValueEntryStringCastError($context, $keyEntry);
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($afterEnumCheck);

        $stringBlock = BasicBlockHelper::append($context, 'array_combine_key_string');
        $longBlock = BasicBlockHelper::append($context, 'array_combine_key_long');
        $done = BasicBlockHelper::append($context, 'array_combine_key_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_combine_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $keyEntry
        );
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($done);

        $afterLong = BasicBlockHelper::append($context, 'array_combine_after_long');
        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $keyEntry
        );
        $isResource = JitValueCompare::nativeLongIsResource($context, $longVal);
        $longPlainBlock = BasicBlockHelper::append($context, 'array_combine_key_long_plain');
        $longResBlock = BasicBlockHelper::append($context, 'array_combine_key_long_resource');
        $context->builder->branchIf($isResource, $longResBlock, $longPlainBlock);

        $context->builder->positionAtEnd($longPlainBlock);
        $intKey = $context->builder->truncOrBitCast($longVal, $sizeT);
        self::storeValueEntryAtIndex($context, $dest, $intKey, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longResBlock);
        $keyStrFromRes = self::formatResourceIdStringKey($context, $longVal);
        self::storeValueEntryAtStringKey($context, $dest, $keyStrFromRes, $valEntry);
        $context->builder->branch($done);

        $doubleBlock = BasicBlockHelper::append($context, 'array_combine_key_double');
        $doubleWholeBlock = BasicBlockHelper::append($context, 'array_combine_key_double_whole');
        $doubleStringBlock = BasicBlockHelper::append($context, 'array_combine_key_double_str');
        $afterDouble = BasicBlockHelper::append($context, 'array_combine_after_double');
        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $keyEntry
        );
        $longFromDouble = $context->builder->fptosi($doubleVal, $sizeT);
        $doubleFromLong = $context->builder->sitofp(
            $longFromDouble,
            $context->getTypeFromString('double')
        );
        $isWholeDouble = $context->builder->fcmp(Builder::REAL_OEQ, $doubleVal, $doubleFromLong);
        $context->builder->branchIf($isWholeDouble, $doubleWholeBlock, $doubleStringBlock);

        $context->builder->positionAtEnd($doubleWholeBlock);
        self::storeValueEntryAtIndex($context, $dest, $longFromDouble, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($doubleStringBlock);
        $keyStrFromDouble = self::formatDoubleStringKey($context, $doubleVal);
        self::storeValueEntryAtStringKey($context, $dest, $keyStrFromDouble, $valEntry);
        $context->builder->branch($done);

        $boolBlock = BasicBlockHelper::append($context, 'array_combine_key_bool');
        $nullBlock = BasicBlockHelper::append($context, 'array_combine_key_null');
        $objectErrorBlock = BasicBlockHelper::append($context, 'array_combine_key_object_error');
        $defaultErrorBlock = BasicBlockHelper::append($context, 'array_combine_key_default_error');

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'array_combine_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolKey = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry),
            $sizeT
        );
        self::storeValueEntryAtIndex($context, $dest, $boolKey, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'array_combine_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $emptyKey = $context->builder->load($context->constantStringFromString(''));
        self::storeValueEntryAtStringKey($context, $dest, $emptyKey, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        $arrayBlock = BasicBlockHelper::append($context, 'array_combine_key_array');
        $afterArray = BasicBlockHelper::append($context, 'array_combine_after_array');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ARRAY, false)
        );
        $context->builder->branchIf($isArray, $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitBuiltinWarning($context, 'Array to string conversion');
        $arrayKey = $context->builder->load($context->constantStringFromString('Array'));
        self::storeValueEntryAtStringKey($context, $dest, $arrayKey, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectErrorBlock, $defaultErrorBlock);

        $context->builder->positionAtEnd($objectErrorBlock);
        $context->type->object->emitObjectValueEntryStringCastError($context, $keyEntry);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($defaultErrorBlock);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, 'Object of class stdClass could not be converted to string');
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /** php-src convert_to_key: fractional doubles become string keys (ext/standard/array.c). */
    private static function formatDoubleStringKey(Context $context, Value $doubleVal): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast($context->constantFromString('%G'), $charPtr);
        $written = $context->builder->call($context->lookupFunction('snprintf'), $bufChar, $bufSize, $fmt, $doubleVal);
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $len, $bufChar);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    /** php-src convert_to_key: resource handles become "Resource id #N" string keys (ext/standard/array.c, #10847). */
    private static function formatResourceIdStringKey(Context $context, Value $handleLong): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(32, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString(\PHPCompiler\VM\ValueEchoSupport::RESOURCE_FORMAT),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $handleLong
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $len, $bufChar);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function storeValueEntryAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'array_combine_val_long');
        $stringBlock = BasicBlockHelper::append($context, 'array_combine_val_string');
        $doubleBlock = BasicBlockHelper::append($context, 'array_combine_val_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_combine_val_bool');
        $done = BasicBlockHelper::append($context, 'array_combine_val_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_combine_val_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $index,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'array_combine_val_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'array_combine_val_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setBoolAt'),
            $dest,
            $index,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $context->builder->branchIf($isDouble, $doubleBlock, $done);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setDoubleAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function storeValueEntryAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'array_combine_sval_long');
        $stringBlock = BasicBlockHelper::append($context, 'array_combine_sval_string');
        $boolBlock = BasicBlockHelper::append($context, 'array_combine_sval_bool');
        $done = BasicBlockHelper::append($context, 'array_combine_sval_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_combine_sval_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $keyStr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'array_combine_sval_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'array_combine_sval_after_bool');
        $htBlock = BasicBlockHelper::append($context, 'array_combine_sval_ht');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $dest,
            $keyStr,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $htBlock, $done);

        $context->builder->positionAtEnd($htBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function copyInto(Context $context, Value $dest, Value $src): void
    {
        self::copyReindexableInto($context, $dest, $src);
    }

    /** Deep copy hashtable (packed + string keys) for (array) cast (#4887, VM CastSupport::toArray). */
    public static function duplicateHashtable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::copyReindexableInto($context, $dest, $src);
        self::mergeStringKeysInto($context, $dest, $src);

        return $dest;
    }

    /**
     * Append values from integer-key / numeric-string-key arrays (#3607, #4231).
     */
    public static function copyReindexableInto(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $oneI64 = $i64->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'merge_idx');
        $context->builder->store($zero, $idxSlot);

        $done = BasicBlockHelper::append($context, 'merge_copy_done');
        $head = BasicBlockHelper::append($context, 'merge_copy_head');
        $body = BasicBlockHelper::append($context, 'merge_copy_body');
        $bodyInt = BasicBlockHelper::append($context, 'merge_copy_body_int');
        $bodyStr = BasicBlockHelper::append($context, 'merge_copy_body_str');
        $bodyStore = BasicBlockHelper::append($context, 'merge_copy_body_store');
        $bodySkip = BasicBlockHelper::append($context, 'merge_copy_body_skip');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $presentInt = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($presentInt, $bodyInt, $bodyStr);

        $context->builder->positionAtEnd($bodyInt);
        $srcEntryInt = self::listEntryAt($context, $src, $idx);
        $context->builder->branch($bodyStore);

        $context->builder->positionAtEnd($bodyStr);
        $idxI64 = $context->builder->zExt($idx, $i64);
        $keyStr = \PHPCompiler\JIT\JitNativeString::formatIndexKey($context, $idxI64);
        $presentStr = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $src,
            $keyStr
        );
        $bodyStrHit = BasicBlockHelper::append($context, 'merge_copy_body_str_hit');
        $context->builder->branchIf($presentStr, $bodyStrHit, $bodySkip);

        $context->builder->positionAtEnd($bodyStrHit);
        $srcEntryStr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $src,
            $keyStr
        );
        $context->builder->branch($bodyStore);

        $context->builder->positionAtEnd($bodyStore);
        $srcEntry = $context->builder->phi($srcEntryInt->typeOf());
        $srcEntry->addIncoming($srcEntryInt, $bodyInt);
        $srcEntry->addIncoming($srcEntryStr, $bodyStrHit);
        self::appendValueEntryToPacked($context, $dest, $srcEntry);
        $context->builder->branch($bodySkip);

        $context->builder->positionAtEnd($bodySkip);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    public static function inArray(
        Context $context,
        Variable $needle,
        Variable $haystack,
        Value $strict,
        string $tagSuffix = ''
    ): Value {
        $tag = '' === $tagSuffix ? '' : '_'.$tagSuffix;
        $ht = self::isNativeArray($haystack->type)
            ? self::nativeListToHashTable($context, $haystack)
            : self::loadHashTable($context, $haystack);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'in_array_idx'.$tag);
        $context->builder->store($zero, $idxSlot);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'in_array_found'.$tag
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'in_array_done'.$tag);
        $head = BasicBlockHelper::append($context, 'in_array_head'.$tag);
        $body = BasicBlockHelper::append($context, 'in_array_body'.$tag);
        $bodyMatch = BasicBlockHelper::append($context, 'in_array_body_match'.$tag);
        $foundBlock = BasicBlockHelper::append($context, 'in_array_found_block'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $continueBlock = BasicBlockHelper::append($context, 'in_array_continue'.$tag);
        $context->builder->branchIf($isSet, $bodyMatch, $continueBlock);

        $context->builder->positionAtEnd($bodyMatch);
        $entry = self::listEntryAt($context, $ht, $idx);
        $match = self::entryMatchesNeedle($context, $entry, $needle, $strict);
        $context->builder->branchIf($match, $foundBlock, $continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * array_search() for packed lists and string-keyed assoc arrays (subset of PHP).
     *
     * @return Value
     * (key as long/string, or boolean false)
     */
    public static function arraySearch(
        Context $context,
        Variable $needle,
        Variable $haystack,
        Value $strict
    ): Value {
        if (self::isNativeArray($haystack->type)) {
            return self::arraySearchNative($context, $needle, $haystack, $strict);
        }

        $ht = self::loadHashTable($context, $haystack);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));

        $done = BasicBlockHelper::append($context, 'array_search_done');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_search_idx');
        $context->builder->store($zero, $idxSlot);
        $packedHead = BasicBlockHelper::append($context, 'array_search_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_search_packed_body');
        $packedFound = BasicBlockHelper::append($context, 'array_search_packed_found');
        $packedFoundWrite = BasicBlockHelper::append($context, 'array_search_packed_write');
        $packedNext = BasicBlockHelper::append($context, 'array_search_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_search_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedFound, $packedNext);

        $context->builder->positionAtEnd($packedFound);
        $entry = self::listEntryAt($context, $ht, $idx);
        $match = self::entryMatchesNeedle($context, $entry, $needle, $strict);
        $context->builder->branchIf($match, $packedFoundWrite, $packedNext);

        $context->builder->positionAtEnd($packedFoundWrite);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $i64)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_search_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_search_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $ptrSize = $sizeT->constInt(8, false);
        $strCountSlot = $context->builder->alloca($sizeT, 1, 'array_search_str_count');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'array_search_str_nodes');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_search_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = BasicBlockHelper::append($context, 'array_search_str_count_head');
        $countBody = BasicBlockHelper::append($context, 'array_search_str_count_body');
        $countDone = BasicBlockHelper::append($context, 'array_search_str_count_done');
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countHead);
        $walkNode = $context->builder->load($walkSlot);
        $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($walkEnd, $countDone, $countBody);
        $context->builder->positionAtEnd($countBody);
        $strCount = $context->builder->load($strCountSlot);
        $context->builder->store($context->builder->addNoSignedWrap($strCount, $one), $strCountSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countDone);
        $numStrKeys = $context->builder->load($strCountSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $numStrKeys, $zero);
        $strEmpty = BasicBlockHelper::append($context, 'array_search_str_empty');
        $strWork = BasicBlockHelper::append($context, 'array_search_str_work');
        $context->builder->branchIf($isEmpty, $strEmpty, $strWork);
        $context->builder->positionAtEnd($strEmpty);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($strWork);
        $bytes = $context->builder->mulNoSignedWrap($numStrKeys, $ptrSize);
        $nodesRaw = $context->builder->call($context->lookupFunction('malloc'), $bytes);
        $nodesArray = $context->builder->pointerCast($nodesRaw, $nodePtrType->pointerType(0));
        $context->builder->store($nodesArray, $nodesSlot);
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);
        $fillHead = BasicBlockHelper::append($context, 'array_search_str_fill_head');
        $fillBody = BasicBlockHelper::append($context, 'array_search_str_fill_body');
        $fillDone = BasicBlockHelper::append($context, 'array_search_str_fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $fillIdx = $context->builder->load($strCountSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $context->builder->store($fillNode, $context->builder->inBoundsGEP($nodesArray, $fillIdx));
        $context->builder->store($context->builder->addNoSignedWrap($fillIdx, $one), $strCountSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);
        $strIdxSlot = $context->builder->alloca($sizeT, 1, 'array_search_str_idx');
        $context->builder->store($zero, $strIdxSlot);
        $strBody = BasicBlockHelper::append($context, 'array_search_str_body');
        $strFound = BasicBlockHelper::append($context, 'array_search_str_found');
        $strNext = BasicBlockHelper::append($context, 'array_search_str_next');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $nodeIdx = $context->builder->load($strIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $nodeIdx, $numStrKeys);
        $strDrain = BasicBlockHelper::append($context, 'array_search_str_drain');
        $context->builder->branchIf($atEnd, $strDrain, $strBody);

        $context->builder->positionAtEnd($strDrain);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($strBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $node = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $nodeIdx));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $match = self::entryMatchesNeedle($context, $valEntry, $needle, $strict);
        $context->builder->branchIf($match, $strFound, $strNext);

        $context->builder->positionAtEnd($strFound);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $resultPtr,
            $owned
        );
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($strNext);
        $context->builder->store($context->builder->addNoSignedWrap($nodeIdx, $one), $strIdxSlot);
        $context->builder->branch($strHead);

        $retBlock = BasicBlockHelper::append($context, 'array_search_return');
        $context->builder->positionAtEnd($done);
        $context->builder->branch($retBlock);
        $context->builder->positionAtEnd($retBlock);

        return $resultPtr;
    }

    private static function arraySearchNative(
        Context $context,
        Variable $needle,
        Variable $array,
        Value $strict
    ): Value {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_search_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_search_native_head');
        $body = BasicBlockHelper::append($context, 'array_search_native_body');
        $foundWrite = BasicBlockHelper::append($context, 'array_search_native_write');
        $next = BasicBlockHelper::append($context, 'array_search_native_next');
        $done = BasicBlockHelper::append($context, 'array_search_native_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $cand = new Variable($context, $elemType, Variable::KIND_VALUE, $elem);
        $match = self::valuesEqual($context, $needle, $cand, $strict);
        $context->builder->branchIf($match, $foundWrite, $next);

        $context->builder->positionAtEnd($foundWrite);
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $i64)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $retBlock = BasicBlockHelper::append($context, 'array_search_native_return');
        $context->builder->positionAtEnd($done);
        $context->builder->branch($retBlock);
        $context->builder->positionAtEnd($retBlock);

        return JitValueBox::pointer($context, $resultSlot);
    }

    /**
     * Unbox a TYPE_VALUE needle (e.g. $_GET['route']) for comparison with a typed candidate.
     */
    private static function coerceNeedleForCompare(Context $context, Variable $needle, int $targetType): Variable
    {
        if ($needle->type === $targetType) {
            return $needle;
        }
        if (Variable::TYPE_VALUE !== $needle->type) {
            return $needle;
        }
        if (Variable::TYPE_STRING === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readString'), $needle->value)
            );
        }
        if (Variable::TYPE_NATIVE_LONG === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readLong'), $needle->value)
            );
        }
        if (Variable::TYPE_NATIVE_BOOL === $targetType) {
            $i1 = $context->getTypeFromString('int1');

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $context->builder->truncOrBitCast(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $needle->value),
                    $i1
                )
            );
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_DOUBLE,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readDouble'), $needle->value)
            );
        }
        if (Variable::TYPE_OBJECT === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readObject'), $needle->value)
            );
        }

        return $needle;
    }

    private static function entryMatchesNeedle(
        Context $context,
        Value $entry,
        Variable $needle,
        Value $strict
    ): Value {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $baseType = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $i1 = $context->getTypeFromString('int1');
        $false = $i1->constInt(0, false);
        $resultSlot = $context->builder->alloca($i1, 1, 'entry_match');
        $context->builder->store($false, $resultSlot);

        $bbString = BasicBlockHelper::append($context, 'entry_match_string');
        $bbCheckLong = BasicBlockHelper::append($context, 'entry_match_check_long');
        $bbLong = BasicBlockHelper::append($context, 'entry_match_long');
        $bbCheckBool = BasicBlockHelper::append($context, 'entry_match_check_bool');
        $bbBool = BasicBlockHelper::append($context, 'entry_match_bool');
        $bbCheckDouble = BasicBlockHelper::append($context, 'entry_match_check_double');
        $bbDouble = BasicBlockHelper::append($context, 'entry_match_double');
        $bbCheckHashtable = BasicBlockHelper::append($context, 'entry_match_check_hashtable');
        $bbHashtable = BasicBlockHelper::append($context, 'entry_match_hashtable');
        $bbCheckObject = BasicBlockHelper::append($context, 'entry_match_check_object');
        $bbObject = BasicBlockHelper::append($context, 'entry_match_object');
        $bbNull = BasicBlockHelper::append($context, 'entry_match_null');
        $bbDone = BasicBlockHelper::append($context, 'entry_match_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $bbString, $bbCheckLong);

        $context->builder->positionAtEnd($bbString);
        $strCand = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_STRING), $strCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbLong);
        $longCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_LONG), $longCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $bbBool, $bbCheckDouble);

        $context->builder->positionAtEnd($bbBool);
        $boolCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $entry),
                $i1
            )
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_BOOL), $boolCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckDouble);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE & 0x7f, false)
        );
        $context->builder->branchIf($isDouble, $bbDouble, $bbCheckHashtable);

        $context->builder->positionAtEnd($bbDouble);
        $doubleCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_DOUBLE), $doubleCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckHashtable);
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $context->builder->branchIf($isHashtable, $bbHashtable, $bbCheckObject);

        $context->builder->positionAtEnd($bbCheckObject);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $baseType,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf($isObject, $bbObject, $bbNull);

        $context->builder->positionAtEnd($bbObject);
        $objCand = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readObject'), $entry)
        );
        if (Variable::TYPE_VALUE === $needle->type) {
            $objectMatch = JitValueCompare::identicalValueBoxToObject($context, $needle, $objCand);
        } else {
            $objectMatch = self::valuesEqual(
                $context,
                self::coerceNeedleForCompare($context, $needle, Variable::TYPE_OBJECT),
                $objCand,
                $strict
            );
        }
        $context->builder->store($objectMatch, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbHashtable);
        $htCand = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, $needle, $htCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNull);
        if (Variable::TYPE_NULL === $needle->type) {
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $baseType,
                $i8->constInt(Variable::TYPE_NULL & 0x7f, false)
            );
            $context->builder->store($isNull, $resultSlot);
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    private static function readListElement(
        Context $context,
        Value $ht,
        Value $index,
        int $preferredType
    ): Variable {
        if (Variable::TYPE_STRING === $preferredType) {
            $str = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $index
            );

            return new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str);
        }
        $val = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $index
        );

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $val);
    }

    private static function valuesEqual(
        Context $context,
        Variable $left,
        Variable $right,
        Value $strict
    ): Value {
        $strictEq = self::strictEqual($context, $left, $right);
        $looseEq = self::looseEqual($context, $left, $right);

        return $context->builder->select($strict, $strictEq, $looseEq);
    }

    private static function strictEqual(Context $context, Variable $left, Variable $right): Value
    {
        if ($left->type !== $right->type) {
            return $context->constantFromBool(false);
        }

        return self::sameTypeEqual($context, $left, $right);
    }

    private static function looseEqual(Context $context, Variable $left, Variable $right): Value
    {
        if ($left->type === $right->type) {
            if (Variable::TYPE_STRING === $left->type) {
                return JitValueCompare::looseEqualStringToString(
                    $context,
                    $context->helper->loadValue($left),
                    $context->helper->loadValue($right)
                );
            }

            return self::sameTypeEqual($context, $left, $right);
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type && Variable::TYPE_NATIVE_DOUBLE === $right->type) {
            $l = $context->helper->loadValue($left);
            $r = $context->helper->loadValue($right);
            $lf = $context->builder->siToFp($l, $context->getTypeFromString('double'));

            return $context->builder->fcmp(Builder::REAL_OEQ, $lf, $r);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $left->type && Variable::TYPE_NATIVE_LONG === $right->type) {
            return self::looseEqual($context, $right, $left);
        }
        if (Variable::TYPE_STRING === $left->type && Variable::TYPE_NATIVE_LONG === $right->type) {
            return self::looseEqualStringLong($context, $left, $right);
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type && Variable::TYPE_STRING === $right->type) {
            return self::looseEqualStringLong($context, $right, $left);
        }
        if (Variable::TYPE_HASHTABLE === $left->type && Variable::TYPE_NATIVE_BOOL === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $left, $context->helper->loadValue($right));
        }
        if (Variable::TYPE_NATIVE_BOOL === $left->type && Variable::TYPE_HASHTABLE === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $right, $context->helper->loadValue($left));
        }
        if (ArrayBuiltinHelper::isNativeArray($left->type) && Variable::TYPE_NATIVE_BOOL === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $left, $context->helper->loadValue($right));
        }
        if (Variable::TYPE_NATIVE_BOOL === $left->type && ArrayBuiltinHelper::isNativeArray($right->type)) {
            return JitValueCompare::looseEqualArrayToBool($context, $right, $context->helper->loadValue($left));
        }
        if (Variable::TYPE_HASHTABLE === $left->type && Variable::TYPE_NULL === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $left);
        }
        if (Variable::TYPE_NULL === $left->type && Variable::TYPE_HASHTABLE === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $right);
        }
        if (ArrayBuiltinHelper::isNativeArray($left->type) && Variable::TYPE_NULL === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $left);
        }
        if (Variable::TYPE_NULL === $left->type && ArrayBuiltinHelper::isNativeArray($right->type)) {
            return JitValueCompare::looseEqualArrayToNull($context, $right);
        }

        return $context->constantFromBool(false);
    }

    private static function looseEqualStringLong(Context $context, Variable $str, Variable $long): Value
    {
        return JitValueCompare::looseEqualStringToNativeLong(
            $context,
            $context->helper->loadValue($str),
            $context->helper->loadValue($long)
        );
    }

    public static function ksortByKey(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'ksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'ksort_done');
        $sort = BasicBlockHelper::append($context, 'ksort_sort');
        $context->builder->branchIf($isList, $done, $sort);

        $context->builder->positionAtEnd($sort);
        self::sortStringKeys($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function ksortByKeyLocale(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'ksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'ksort_locale_done');
        $sort = BasicBlockHelper::append($context, 'ksort_locale_sort');
        $context->builder->branchIf($isList, $done, $sort);

        $context->builder->positionAtEnd($sort);
        self::sortStringKeysLocale($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeys(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeys'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeysLocale(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeysLocale'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function krsortByKey(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'krsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'krsort_done');
        $sortList = BasicBlockHelper::append($context, 'krsort_sort_list');
        $sort = BasicBlockHelper::append($context, 'krsort_sort');
        $context->builder->branchIf($isList, $sortList, $sort);

        $context->builder->positionAtEnd($sortList);
        self::krsortPackedListByKey($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sort);
        self::sortStringKeysReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * krsort() on dense 0..n-1 lists — rebuild with keys n-1..0 (php-src; issue #10836).
     */
    private static function krsortPackedListByKey(Context $context, Variable $array): void
    {
        $src = self::loadHashTable($context, $array);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $two = $sizeT->constInt(2, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $tooSmall = $context->builder->icmp(Builder::INT_ULT, $num, $two);
        $done = BasicBlockHelper::append($context, 'krsort_packed_list_done');
        $work = BasicBlockHelper::append($context, 'krsort_packed_list_work');
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $dest = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $kSlot = $context->builder->alloca($i64, 1, 'krsort_packed_k');
        $numI64 = $context->builder->zExt($num, $i64);
        $context->builder->store($context->builder->subNoSignedWrap($numI64, $oneI64), $kSlot);

        $loopHead = BasicBlockHelper::append($context, 'krsort_packed_head');
        $loopBody = BasicBlockHelper::append($context, 'krsort_packed_body');
        $loopNext = BasicBlockHelper::append($context, 'krsort_packed_next');
        $storeDone = BasicBlockHelper::append($context, 'krsort_packed_store_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $k = $context->builder->load($kSlot);
        $kNegative = $context->builder->icmp(Builder::INT_SLT, $k, $zeroI64);
        $context->builder->branchIf($kNegative, $storeDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $kIndex = $context->builder->truncOrBitCast($k, $sizeT);
        $valueBox = HashTableHelper::readIndexedToValueBox($context, $src, $kIndex);
        $keyStr = \PHPCompiler\JIT\JitNativeString::formatIndexKey($context, $k);
        HashTableHelper::setAtStringKey($context, $dest, $keyStr, $valueBox);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->subNoSignedWrap($k, $oneI64), $kSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($storeDone);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeysReverse(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeysReverse'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function asortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'asort_done');
        $sortList = BasicBlockHelper::append($context, 'asort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'asort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        SortRuntime::sortPacked($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValues($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function asortByValueLocale(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'asort_locale_done');
        $sortList = BasicBlockHelper::append($context, 'asort_locale_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'asort_locale_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        SortRuntime::sortPackedLocale($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesLocale($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * natsort()/natcasesort() on packed lists — rebuild with original keys preserved (#9600).
     */
    public static function sortListNaturalPreserveKeys(Context $context, Variable $array, string $compareFn): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $src = self::loadHashTable($context, $array);
        self::rebuildListNaturalSortedPreserveKeysInPlace($context, $src, $compareFn);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $src);
    }

    private static function rebuildListNaturalSortedPreserveKeysInPlace(
        Context $context,
        Value $src,
        string $compareFn
    ): void {
        $dest = self::buildListNaturalSortedHashtable($context, $src, $compareFn);
        self::copyHashtablePayload($context, $dest, $src);
    }

    private static function buildListNaturalSortedHashtable(
        Context $context,
        Value $src,
        string $compareFn
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);
        $num = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $tooSmall = $context->builder->icmp(Builder::INT_ULT, $num, $two);
        $doneSmall = BasicBlockHelper::append($context, 'natsort_preserve_small');
        $work = BasicBlockHelper::append($context, 'natsort_preserve_work');
        $doneAll = BasicBlockHelper::append($context, 'natsort_preserve_done');
        $context->builder->branchIf($tooSmall, $doneSmall, $work);

        $context->builder->positionAtEnd($doneSmall);
        $smallBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneAll);

        $context->builder->positionAtEnd($work);
        $ptrSize = $sizeT->constInt(8, false);
        $permBytes = $context->builder->mulNoSignedWrap($num, $ptrSize);
        $permRaw = $context->builder->call($context->lookupFunction('malloc'), $permBytes);
        $perm = $context->builder->pointerCast($permRaw, $sizeT->pointerType(0));

        $initIdxSlot = $context->builder->alloca($sizeT, 1, 'natsort_perm_init');
        $context->builder->store($zero, $initIdxSlot);
        $initHead = BasicBlockHelper::append($context, 'natsort_perm_init_head');
        $initBody = BasicBlockHelper::append($context, 'natsort_perm_init_body');
        $initDone = BasicBlockHelper::append($context, 'natsort_perm_init_done');
        $context->builder->branch($initHead);

        $context->builder->positionAtEnd($initHead);
        $initIdx = $context->builder->load($initIdxSlot);
        $initAtEnd = $context->builder->icmp(Builder::INT_SGE, $initIdx, $num);
        $context->builder->branchIf($initAtEnd, $initDone, $initBody);

        $context->builder->positionAtEnd($initBody);
        $context->builder->store($initIdx, $context->builder->inBoundsGEP($perm, $initIdx));
        $context->builder->store($context->builder->addNoSignedWrap($initIdx, $one), $initIdxSlot);
        $context->builder->branch($initHead);

        $context->builder->positionAtEnd($initDone);
        $firstEntry = self::listEntryAt($context, $src, $zero);
        $i8 = $context->getTypeFromString('int8');
        $firstType = $context->builder->load(
            $context->builder->structGep($firstEntry, $valueMap['type'])
        );
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $firstType, $stringTag);
        $sortStrings = BasicBlockHelper::append($context, 'natsort_preserve_sort_str');
        $sortLongs = BasicBlockHelper::append($context, 'natsort_preserve_sort_long');
        $afterSort = BasicBlockHelper::append($context, 'natsort_preserve_after_sort');
        $context->builder->branchIf($isString, $sortStrings, $sortLongs);

        $context->builder->positionAtEnd($sortStrings);
        self::emitPermBubbleSortStrings($context, $src, $perm, $num, $compareFn);
        $context->builder->branch($afterSort);

        $context->builder->positionAtEnd($sortLongs);
        self::emitPermBubbleSortLongs($context, $src, $perm, $num);
        $context->builder->branch($afterSort);

        $context->builder->positionAtEnd($afterSort);
        $dest = HashTableHelper::alloc($context);
        $writeIdxSlot = $context->builder->alloca($sizeT, 1, 'natsort_preserve_write');
        $context->builder->store($zero, $writeIdxSlot);
        $writeHead = BasicBlockHelper::append($context, 'natsort_preserve_write_head');
        $writeBody = BasicBlockHelper::append($context, 'natsort_preserve_write_body');
        $writeDone = BasicBlockHelper::append($context, 'natsort_preserve_write_done');
        $context->builder->branch($writeHead);

        $context->builder->positionAtEnd($writeHead);
        $writeIdx = $context->builder->load($writeIdxSlot);
        $writeAtEnd = $context->builder->icmp(Builder::INT_SGE, $writeIdx, $num);
        $context->builder->branchIf($writeAtEnd, $writeDone, $writeBody);

        $context->builder->positionAtEnd($writeBody);
        $key = $context->builder->load($context->builder->inBoundsGEP($perm, $writeIdx));
        self::copyPackedListEntry($context, $src, $key, $dest, $key);
        $context->builder->store($context->builder->addNoSignedWrap($writeIdx, $one), $writeIdxSlot);
        $context->builder->branch($writeHead);

        $context->builder->positionAtEnd($writeDone);
        $workEndBlock = $context->builder->getInsertBlock();
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->builder->pointerCast($permRaw, $context->getTypeFromString('int8*'))
        );
        $context->builder->branch($doneAll);

        $context->builder->positionAtEnd($doneAll);
        $resultPhi = $context->builder->phi($src->typeOf());
        $resultPhi->addIncoming($src, $smallBlock);
        $resultPhi->addIncoming($dest, $workEndBlock);

        return $resultPhi;
    }

    private static function copyHashtablePayload(Context $context, Value $from, Value $to): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        foreach (['values', 'numElements', 'nextFreeElement', 'capacity', 'strKeys', 'objKeys', 'internalPointer'] as $field) {
            $slot = $context->builder->structGep($to, $map[$field]);
            $loaded = $context->builder->load($context->builder->structGep($from, $map[$field]));
            $context->builder->store($loaded, $slot);
        }
    }

    private static function emitPermBubbleSortStrings(
        Context $context,
        Value $src,
        Value $perm,
        Value $num,
        string $compareFn
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $strMap = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $outerSlot = $context->builder->alloca($sizeT, 1, 'natsort_perm_str_outer');
        $context->builder->store($zero, $outerSlot);
        $outerHead = BasicBlockHelper::append($context, 'natsort_perm_str_outer_head');
        $outerBody = BasicBlockHelper::append($context, 'natsort_perm_str_outer_body');
        $outerDone = BasicBlockHelper::append($context, 'natsort_perm_str_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $outer = $context->builder->load($outerSlot);
        $outerEnd = $context->builder->sub($num, $one);
        $outerAtEnd = $context->builder->icmp(Builder::INT_SGE, $outer, $outerEnd);
        $context->builder->branchIf($outerAtEnd, $outerDone, $outerBody);

        $context->builder->positionAtEnd($outerBody);
        $innerSlot = $context->builder->alloca($sizeT, 1, 'natsort_perm_str_inner');
        $context->builder->store($zero, $innerSlot);
        $limit = $context->builder->sub($num, $outer);
        $limit = $context->builder->sub($limit, $one);
        $innerHead = BasicBlockHelper::append($context, 'natsort_perm_str_inner_head');
        $innerBody = BasicBlockHelper::append($context, 'natsort_perm_str_inner_body');
        $innerDone = BasicBlockHelper::append($context, 'natsort_perm_str_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $inner = $context->builder->load($innerSlot);
        $innerAtEnd = $context->builder->icmp(Builder::INT_SGE, $inner, $limit);
        $context->builder->branchIf($innerAtEnd, $innerDone, $innerBody);

        $context->builder->positionAtEnd($innerBody);
        $nextInner = $context->builder->addNoSignedWrap($inner, $one);
        $idxA = $context->builder->load($context->builder->inBoundsGEP($perm, $inner));
        $idxB = $context->builder->load($context->builder->inBoundsGEP($perm, $nextInner));
        $strA = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringAt'),
            $src,
            $idxA
        );
        $strB = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringAt'),
            $src,
            $idxB
        );
        $cmp = $context->builder->call(
            $context->lookupFunction($compareFn),
            $context->builder->structGep($strA, $strMap['value']),
            $context->builder->structGep($strB, $strMap['value'])
        );
        $needsSwap = $context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false));
        $swapBlock = BasicBlockHelper::append($context, 'natsort_perm_str_swap');
        $noSwap = BasicBlockHelper::append($context, 'natsort_perm_str_no_swap');
        $afterSwap = BasicBlockHelper::append($context, 'natsort_perm_str_after_swap');
        $context->builder->branchIf($needsSwap, $swapBlock, $noSwap);

        $context->builder->positionAtEnd($swapBlock);
        $permInner = $context->builder->inBoundsGEP($perm, $inner);
        $permNext = $context->builder->inBoundsGEP($perm, $nextInner);
        $tmp = $context->builder->load($permInner);
        $context->builder->store($context->builder->load($permNext), $permInner);
        $context->builder->store($tmp, $permNext);
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($noSwap);
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($afterSwap);
        $context->builder->store($nextInner, $innerSlot);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $context->builder->store($context->builder->addNoSignedWrap($outer, $one), $outerSlot);
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
    }

    private static function emitPermBubbleSortLongs(
        Context $context,
        Value $src,
        Value $perm,
        Value $num
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $outerSlot = $context->builder->alloca($sizeT, 1, 'natsort_perm_long_outer');
        $context->builder->store($zero, $outerSlot);
        $outerHead = BasicBlockHelper::append($context, 'natsort_perm_long_outer_head');
        $outerBody = BasicBlockHelper::append($context, 'natsort_perm_long_outer_body');
        $outerDone = BasicBlockHelper::append($context, 'natsort_perm_long_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $outer = $context->builder->load($outerSlot);
        $outerEnd = $context->builder->sub($num, $one);
        $outerAtEnd = $context->builder->icmp(Builder::INT_SGE, $outer, $outerEnd);
        $context->builder->branchIf($outerAtEnd, $outerDone, $outerBody);

        $context->builder->positionAtEnd($outerBody);
        $innerSlot = $context->builder->alloca($sizeT, 1, 'natsort_perm_long_inner');
        $context->builder->store($zero, $innerSlot);
        $limit = $context->builder->sub($num, $outer);
        $limit = $context->builder->sub($limit, $one);
        $innerHead = BasicBlockHelper::append($context, 'natsort_perm_long_inner_head');
        $innerBody = BasicBlockHelper::append($context, 'natsort_perm_long_inner_body');
        $innerDone = BasicBlockHelper::append($context, 'natsort_perm_long_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $inner = $context->builder->load($innerSlot);
        $innerAtEnd = $context->builder->icmp(Builder::INT_SGE, $inner, $limit);
        $context->builder->branchIf($innerAtEnd, $innerDone, $innerBody);

        $context->builder->positionAtEnd($innerBody);
        $nextInner = $context->builder->addNoSignedWrap($inner, $one);
        $idxA = $context->builder->load($context->builder->inBoundsGEP($perm, $inner));
        $idxB = $context->builder->load($context->builder->inBoundsGEP($perm, $nextInner));
        $longA = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $src,
            $idxA
        );
        $longB = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $src,
            $idxB
        );
        $needsSwap = $context->builder->icmp(Builder::INT_SGT, $longA, $longB);
        $swapBlock = BasicBlockHelper::append($context, 'natsort_perm_long_swap');
        $noSwap = BasicBlockHelper::append($context, 'natsort_perm_long_no_swap');
        $afterSwap = BasicBlockHelper::append($context, 'natsort_perm_long_after_swap');
        $context->builder->branchIf($needsSwap, $swapBlock, $noSwap);

        $context->builder->positionAtEnd($swapBlock);
        $permInner = $context->builder->inBoundsGEP($perm, $inner);
        $permNext = $context->builder->inBoundsGEP($perm, $nextInner);
        $tmp = $context->builder->load($permInner);
        $context->builder->store($context->builder->load($permNext), $permInner);
        $context->builder->store($tmp, $permNext);
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($noSwap);
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($afterSwap);
        $context->builder->store($nextInner, $innerSlot);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $context->builder->store($context->builder->addNoSignedWrap($outer, $one), $outerSlot);
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
    }

    public static function natsortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'natsort_done');
        $sortList = BasicBlockHelper::append($context, 'natsort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'natsort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortListNaturalPreserveKeys($context, $array, 'strnatcmp');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesNatural($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeyValuesNatural(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesNatural'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function natcasesortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natcasesort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'natcasesort_done');
        $sortList = BasicBlockHelper::append($context, 'natcasesort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'natcasesort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortListNaturalPreserveKeys($context, $array, 'strnatcasecmp');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesNaturalCase($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeyValuesNaturalCase(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesNaturalCase'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValues(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValues'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValuesLocale(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesLocale'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValuesReverse(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesReverse'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function arsortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'arsort_done');
        $sortList = BasicBlockHelper::append($context, 'arsort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'arsort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        SortRuntime::sortPackedReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function sameTypeEqual(Context $context, Variable $left, Variable $right): Value
    {
        switch ($left->type) {
            case Variable::TYPE_NATIVE_LONG:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->icmp(Builder::INT_EQ, $l, $r);
            case Variable::TYPE_NATIVE_DOUBLE:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->fcmp(Builder::REAL_OEQ, $l, $r);
            case Variable::TYPE_NATIVE_BOOL:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->icmp(Builder::INT_EQ, $l, $r);
            case Variable::TYPE_STRING:
                return JitStringCompare::identical(
                    $context,
                    $context->helper->loadValue($left),
                    $context->helper->loadValue($right)
                );
            case Variable::TYPE_OBJECT:
                $voidp = $context->getTypeFromString('void')->pointerType(0);
                $sizeT = $context->getTypeFromString('size_t');
                $leftPtr = $context->builder->ptrToInt(
                    $context->builder->pointerCast($context->helper->loadValue($left), $voidp),
                    $sizeT
                );
                $rightPtr = $context->builder->ptrToInt(
                    $context->builder->pointerCast($context->helper->loadValue($right), $voidp),
                    $sizeT
                );

                return $context->builder->icmp(Builder::INT_EQ, $leftPtr, $rightPtr);
            case Variable::TYPE_NULL:
                return $context->constantFromBool(true);
            default:
                return $context->constantFromBool(false);
        }
    }

    /**
     * True when __string__* is fully consumed by base-10 strtol (integer numeric string; #3619).
     */
    private static function stringPtrIsIntegerNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'array_sum_str_long_end');
        $nullEnd = $i8p->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );

        return $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
    }

    /**
     * True when __string__* is fully consumed by strtod (float numeric string; #4262).
     */
    private static function stringPtrIsDoubleNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'array_product_str_double_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );

        return $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
    }

    /** True when __string__* is a Zend numeric string (int or float form; #4262). */
    private static function stringPtrIsNumericString(Context $context, Value $strPtr): Value
    {
        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $isDoubleNumeric = self::stringPtrIsDoubleNumeric($context, $strPtr);

        return $context->builder->or($isIntNumeric, $isDoubleNumeric);
    }

    /**
     * php-src array.c: enum case elements are skipped in array_product numeric fold (#5578).
     */
    private static function branchArrayProductStringOrEnumSkipOrInvalid(
        Context $context,
        Value $typeByte,
        BasicBlock $stringBlock,
        BasicBlock $continueBlock,
        BasicBlock $invalidBlock
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $afterEnum = BasicBlockHelper::append($context, 'array_product_after_enum');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $continueBlock, $afterEnum);

        $context->builder->positionAtEnd($afterEnum);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $invalidBlock);
    }

    private static function arrayProductEmitInvalidElementType(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::ARRAY_PRODUCT_ELEMENT_TYPE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
    }

    /** php-src array.c: non-numeric fold operands contribute 0 to array_product (#4278). */
    private static function arrayProductMultiplyIntSlotByZero(Context $context, Value $prodIntSlot): void
    {
        $i64 = $context->getTypeFromString('int64');
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $i64->constInt(0, false)),
            $prodIntSlot
        );
    }

    private static function arraySumAccumulateLongValue(
        Context $context,
        Value $longVal,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $double = $context->getTypeFromString('double');
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_long_as_float');
        $intPath = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_long_as_int');
        $longDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->siToFp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
    }

    private static function arrayProductAccumulateLongValue(
        Context $context,
        Value $longVal,
        Value $prodIntSlot,
        Value $prodFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_product_'.$tag.'_long_as_float');
        $intPath = BasicBlockHelper::append($context, 'array_product_'.$tag.'_long_as_int');
        $longDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $longVal),
            $prodIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store(
            $context->builder->fmul($prodFloat, $context->builder->siToFp($longVal, $double)),
            $prodFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
    }

    private static function stringPtrToDouble(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    /**
     * Emit LLVM to accumulate a __string__* element into array_sum slots (#3619).
     */
    private static function arraySumAccumulateStringPtr(
        Context $context,
        Value $strPtr,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $intBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int');
        $floatBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_float');
        $strDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_done');
        $context->builder->branchIf($isIntNumeric, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $charPtr = $context->builder->structGep(
            $strPtr,
            $context->structFieldMap['__string__']['value']
        );
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'array_sum_'.$tag.'_strtol_end'
        );
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $useFloat = $context->builder->load($useFloatSlot);
        $intAsFloat = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_f');
        $intAsInt = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_i');
        $intPathDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_done');
        $context->builder->branchIf($useFloat, $intAsFloat, $intAsInt);

        $context->builder->positionAtEnd($intAsInt);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intAsFloat);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->sitofp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = self::stringPtrToDouble($context, $strPtr);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_add_float');
        $floatPathDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_float_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->sitofp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($floatPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Emit LLVM to accumulate a string __value__* element into array_sum slots (#3619).
     */
    private static function arraySumAccumulateStringEntry(
        Context $context,
        Value $entry,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        self::arraySumAccumulateStringPtr(
            $context,
            $strPtr,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            $tag
        );
    }

    /**
     * Emit LLVM to accumulate a __string__* element into array_product slots (#3619).
     */
    private static function arrayProductAccumulateStringPtr(
        Context $context,
        Value $strPtr,
        Value $prodIntSlot,
        Value $prodFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        $isNumeric = self::stringPtrIsNumericString($context, $strPtr);
        $validBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_valid');
        $invalidBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_invalid');
        $strDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_done');
        $context->builder->branchIf($isNumeric, $validBlock, $invalidBlock);

        $context->builder->positionAtEnd($invalidBlock);
        self::arrayProductMultiplyIntSlotByZero($context, $prodIntSlot);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($validBlock);
        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $intBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int');
        $floatBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_float');
        $context->builder->branchIf($isIntNumeric, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $charPtr = $context->builder->structGep(
            $strPtr,
            $context->structFieldMap['__string__']['value']
        );
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'array_product_'.$tag.'_strtol_end'
        );
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $useFloat = $context->builder->load($useFloatSlot);
        $intAsFloat = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_f');
        $intAsInt = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_i');
        $intPathDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_done');
        $context->builder->branchIf($useFloat, $intAsFloat, $intAsInt);

        $context->builder->positionAtEnd($intAsInt);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $longVal),
            $prodIntSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intAsFloat);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store(
            $context->builder->fmul($prodFloat, $context->builder->sitofp($longVal, $double)),
            $prodFloatSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = self::stringPtrToDouble($context, $strPtr);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_add_float');
        $floatPathDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_float_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->sitofp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($floatPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Emit LLVM to accumulate a string __value__* element into array_product slots (#3619).
     */
    private static function arrayProductAccumulateStringEntry(
        Context $context,
        Value $entry,
        Value $prodIntSlot,
        Value $prodFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        self::arrayProductAccumulateStringPtr(
            $context,
            $strPtr,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            $tag
        );
    }

    /**
     * array_sum() for packed lists (integers and floats; subset of PHP).
     */
    public static function arraySum(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arraySumNative($context, $array);
        }

        return self::arraySumHashTable($context, self::loadHashTable($context, $array));
    }

    private static function arraySumNative(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        if (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
            $sumSlot = $context->builder->alloca($double, 1, 'array_sum_native_f');
            $context->builder->store($double->constReal(0.0), $sumSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($sumSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_native_f_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_sum_native_f_head');
            $body = BasicBlockHelper::append($context, 'array_sum_native_f_body');
            $done = BasicBlockHelper::append($context, 'array_sum_native_f_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $sum = $context->builder->load($sumSlot);
            $context->builder->store($context->builder->fadd($sum, $elem), $sumSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($sumSlot);
        }

        if (Variable::TYPE_VALUE === $elemType) {
            return self::arraySumNativeValue($context, $array);
        }

        if (Variable::TYPE_STRING === $elemType) {
            return self::arraySumNativeString($context, $array);
        }

        if (Variable::TYPE_NATIVE_BOOL === $elemType) {
            $sumSlot = $context->builder->alloca($i64, 1, 'array_sum_native_b');
            $context->builder->store($i64->constInt(0, false), $sumSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($sumSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_native_b_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_sum_native_b_head');
            $body = BasicBlockHelper::append($context, 'array_sum_native_b_body');
            $done = BasicBlockHelper::append($context, 'array_sum_native_b_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $sum = $context->builder->load($sumSlot);
            $context->builder->store(
                $context->builder->addNoSignedWrap($sum, $context->builder->zext($elem, $i64)),
                $sumSlot
            );
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($sumSlot);
        }

        if (Variable::TYPE_NATIVE_LONG !== $elemType) {
            throw new \LogicException(
                'array_sum() only supports integer and float elements in this compiler build'
            );
        }

        $sumSlot = $context->builder->alloca($i64, 1, 'array_sum_native_i');
        $context->builder->store($i64->constInt(0, false), $sumSlot);
        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_native_i_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_native_i_head');
        $body = BasicBlockHelper::append($context, 'array_sum_native_i_body');
        $done = BasicBlockHelper::append($context, 'array_sum_native_i_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $sum = $context->builder->load($sumSlot);
        $context->builder->store($context->builder->addNoSignedWrap($sum, $elem), $sumSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($sumSlot);
    }

    private static function arraySumHashTable(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_ht_head');
        $body = BasicBlockHelper::append($context, 'array_sum_ht_body');
        $afterLong = BasicBlockHelper::append($context, 'array_sum_ht_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_sum_ht_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_sum_ht_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_sum_ht_after_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_sum_ht_bool');
        $afterBool = BasicBlockHelper::append($context, 'array_sum_ht_after_bool');
        $stringBlock = BasicBlockHelper::append($context, 'array_sum_ht_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_ht_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_ht_done');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $doneBlock, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolLongVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arraySumAccumulateLongValue(
            $context,
            $boolLongVal,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ht_bool'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($afterBool);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arraySumAccumulateStringEntry(
            $context,
            $entry,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arraySumAccumulateLongValue(
            $context,
            $longVal,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_ht_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_ht_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_sum_ht_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->siToFp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /** Native array of boxed __value__ (e.g. array(1, 2.5)). */
    private static function arraySumNativeValue(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_nv_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_nv_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_nv_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_nv_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_nv_head');
        $body = BasicBlockHelper::append($context, 'array_sum_nv_body');
        $afterLong = BasicBlockHelper::append($context, 'array_sum_nv_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_sum_nv_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_sum_nv_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_sum_nv_after_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_sum_nv_bool');
        $afterBool = BasicBlockHelper::append($context, 'array_sum_nv_after_bool');
        $stringBlock = BasicBlockHelper::append($context, 'array_sum_nv_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_nv_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_nv_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolLongVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arraySumAccumulateLongValue(
            $context,
            $boolLongVal,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'nv_bool'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($afterBool);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arraySumAccumulateStringEntry(
            $context,
            $entry,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arraySumAccumulateLongValue(
            $context,
            $longVal,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_nv_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_nv_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_sum_nv_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->siToFp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /** Native packed __string__* list (compile-time string literals; #3619). */
    private static function arraySumNativeString(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_ns_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_ns_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_ns_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_ns_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_ns_head');
        $body = BasicBlockHelper::append($context, 'array_sum_ns_body');
        $accumulate = BasicBlockHelper::append($context, 'array_sum_ns_accumulate');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_ns_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_ns_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $strPtr = $context->builder->load($slot);
        $context->builder->branch($accumulate);

        $context->builder->positionAtEnd($accumulate);
        self::arraySumAccumulateStringPtr(
            $context,
            $strPtr,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ns'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /**
     * array_product() for packed lists (integers and floats; subset of PHP).
     */
    public static function arrayProduct(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arrayProductNative($context, $array);
        }

        return self::arrayProductHashTable($context, self::loadHashTable($context, $array));
    }

    private static function arrayProductNative(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        if (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
            $prodSlot = $context->builder->alloca($double, 1, 'array_product_native_f');
            $context->builder->store($double->constReal(1.0), $prodSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($prodSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_native_f_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_product_native_f_head');
            $body = BasicBlockHelper::append($context, 'array_product_native_f_body');
            $done = BasicBlockHelper::append($context, 'array_product_native_f_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $prod = $context->builder->load($prodSlot);
            $context->builder->store($context->builder->fmul($prod, $elem), $prodSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($prodSlot);
        }

        if (Variable::TYPE_VALUE === $elemType) {
            return self::arrayProductNativeValue($context, $array);
        }

        if (Variable::TYPE_STRING === $elemType) {
            return self::arrayProductNativeString($context, $array);
        }

        if (Variable::TYPE_NATIVE_BOOL === $elemType) {
            $prodSlot = $context->builder->alloca($i64, 1, 'array_product_native_b');
            $context->builder->store($i64->constInt(1, false), $prodSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($prodSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_native_b_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_product_native_b_head');
            $body = BasicBlockHelper::append($context, 'array_product_native_b_body');
            $done = BasicBlockHelper::append($context, 'array_product_native_b_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $prod = $context->builder->load($prodSlot);
            $context->builder->store(
                $context->builder->mulNoSignedWrap($prod, $context->builder->zext($elem, $i64)),
                $prodSlot
            );
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($prodSlot);
        }

        if (Variable::TYPE_NATIVE_LONG !== $elemType) {
            throw new \TypeError(self::ARRAY_PRODUCT_ELEMENT_TYPE_ERROR);
        }

        $prodSlot = $context->builder->alloca($i64, 1, 'array_product_native_i');
        $context->builder->store($i64->constInt(1, false), $prodSlot);
        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_native_i_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_native_i_head');
        $body = BasicBlockHelper::append($context, 'array_product_native_i_body');
        $done = BasicBlockHelper::append($context, 'array_product_native_i_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $prod = $context->builder->load($prodSlot);
        $context->builder->store($context->builder->mulNoSignedWrap($prod, $elem), $prodSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($prodSlot);
    }

    /** Native packed __string__* list (compile-time string literals; #3619). */
    private static function arrayProductNativeString(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_ns_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_ns_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_ns_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_ns_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_ns_head');
        $body = BasicBlockHelper::append($context, 'array_product_ns_body');
        $accumulate = BasicBlockHelper::append($context, 'array_product_ns_accumulate');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_ns_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_ns_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $strPtr = $context->builder->load($slot);
        $context->builder->branch($accumulate);

        $context->builder->positionAtEnd($accumulate);
        self::arrayProductAccumulateStringPtr(
            $context,
            $strPtr,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ns'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    private static function arrayProductHashTable(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_ht_head');
        $body = BasicBlockHelper::append($context, 'array_product_ht_body');
        $afterLong = BasicBlockHelper::append($context, 'array_product_ht_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_product_ht_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_product_ht_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_product_ht_after_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_product_ht_bool');
        $afterBool = BasicBlockHelper::append($context, 'array_product_ht_after_bool');
        $stringBlock = BasicBlockHelper::append($context, 'array_product_ht_string');
        $zeroBlock = BasicBlockHelper::append($context, 'array_product_ht_zero');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_ht_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_ht_done');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $doneBlock, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolLongVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arrayProductAccumulateLongValue(
            $context,
            $boolLongVal,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ht_bool'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($afterBool);
        self::branchArrayProductStringOrEnumSkipOrInvalid(
            $context,
            $typeByte,
            $stringBlock,
            $continueBlock,
            $zeroBlock
        );

        $context->builder->positionAtEnd($zeroBlock);
        self::arrayProductMultiplyIntSlotByZero($context, $prodIntSlot);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arrayProductAccumulateStringEntry(
            $context,
            $entry,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arrayProductAccumulateLongValue(
            $context,
            $longVal,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_ht_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_ht_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_product_ht_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->siToFp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    /** Native array of boxed __value__ (e.g. array(1, 2.5)). */
    private static function arrayProductNativeValue(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_nv_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_nv_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_nv_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_nv_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_nv_head');
        $body = BasicBlockHelper::append($context, 'array_product_nv_body');
        $afterLong = BasicBlockHelper::append($context, 'array_product_nv_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_product_nv_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_product_nv_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_product_nv_after_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_product_nv_bool');
        $afterBool = BasicBlockHelper::append($context, 'array_product_nv_after_bool');
        $stringBlock = BasicBlockHelper::append($context, 'array_product_nv_string');
        $zeroBlock = BasicBlockHelper::append($context, 'array_product_nv_zero');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_nv_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_nv_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolLongVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arrayProductAccumulateLongValue(
            $context,
            $boolLongVal,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'nv_bool'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($afterBool);
        self::branchArrayProductStringOrEnumSkipOrInvalid(
            $context,
            $typeByte,
            $stringBlock,
            $continueBlock,
            $zeroBlock
        );

        $context->builder->positionAtEnd($zeroBlock);
        self::arrayProductMultiplyIntSlotByZero($context, $prodIntSlot);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arrayProductAccumulateStringEntry(
            $context,
            $entry,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::arrayProductAccumulateLongValue(
            $context,
            $longVal,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_nv_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_nv_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_product_nv_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->siToFp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    /**
     * array_unique() for arrays of scalar values (ext/standard/array.c subset).
     *
     * @param int $flags SORT_REGULAR (loose ==), SORT_STRING (string cast), or SORT_NUMERIC
     */
    public static function arrayUnique(Context $context, Variable $array, int $flags = 0): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arrayUniqueHashTable($context, self::nativeListToHashTable($context, $array), $flags);
        }

        return self::arrayUniqueHashTable($context, self::loadHashTable($context, $array), $flags);
    }

    private static function arrayUniqueHashTable(Context $context, Value $src, int $flags): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unique_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_unique_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_unique_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_unique_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_unique_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_unique_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_unique_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_unique_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry, $flags);
        $context->builder->branchIf($duplicate, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_unique_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_unique_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_unique_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_unique_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_unique_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_unique_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_unique_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_unique_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry, $flags);
        $context->builder->branchIf($duplicate, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        BasicBlockHelper::branchToFreshContinue($context, 'array_unique_continue');

        return $dest;
    }

    /**
     * Duplicate check against values already stored in $dest.
     *
     * @param int $flags SORT_REGULAR (loose ==), SORT_STRING (string cast), or SORT_NUMERIC
     */
    private static function destContainsPackedEntry(Context $context, Value $dest, Value $entry, int $flags): Value
    {
        $sortType = $flags & ~\PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE;
        if (\PHPCompiler\ext\standard\StdlibConstants::SORT_STRING === $sortType) {
            return self::destContainsPackedEntryString($context, $dest, $entry);
        }
        if (\PHPCompiler\ext\standard\StdlibConstants::SORT_NUMERIC === $sortType) {
            return self::destContainsPackedEntryNumeric($context, $dest, $entry);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $strict = $i1->constInt(0, false);
        $falseVal = $i1->constInt(0, false);
        $destVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $dest);

        $dupSlot = $context->builder->alloca($i1, 1, 'array_unique_dup');
        $context->builder->store($falseVal, $dupSlot);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );

        $stringBlock = BasicBlockHelper::append($context, 'array_unique_dup_string');
        $longBlock = BasicBlockHelper::append($context, 'array_unique_dup_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_unique_dup_double');
        $falseBlock = BasicBlockHelper::append($context, 'array_unique_dup_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_unique_dup_merge');

        $afterString = BasicBlockHelper::append($context, 'array_unique_dup_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::inArray($context, $needle, $destVar, $strict),
            $dupSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'array_unique_dup_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::inArray($context, $needle, $destVar, $strict),
            $dupSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $falseBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry)
        );
        $context->builder->store(
            self::inArray($context, $needle, $destVar, $strict),
            $dupSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($dupSlot);
    }

    /**
     * SORT_STRING duplicate check: compare string casts (ext/standard string_compare_function).
     */
    private static function destContainsPackedEntryString(Context $context, Value $dest, Value $entry): Value
    {
        $strval = new strval();
        $needleStr = $strval->valueToString($context, $entry);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unique_str_dup_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $dest
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'array_unique_str_dup_found'
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'array_unique_str_dup_done');
        $head = BasicBlockHelper::append($context, 'array_unique_str_dup_head');
        $body = BasicBlockHelper::append($context, 'array_unique_str_dup_body');
        $foundBlock = BasicBlockHelper::append($context, 'array_unique_str_dup_found_block');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $candEntry = self::listEntryAt($context, $dest, $idx);
        $candStr = $strval->valueToString($context, $candEntry);
        $match = JitStringCompare::identical($context, $candStr, $needleStr);
        $continueBlock = BasicBlockHelper::append($context, 'array_unique_str_dup_continue');
        $context->builder->branchIf($match, $foundBlock, $continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * SORT_NUMERIC duplicate check (ext/standard/array.c numeric_compare_function).
     */
    private static function destContainsPackedEntryNumeric(Context $context, Value $dest, Value $entry): Value
    {
        $needleNum = self::valueEntryToNumericDouble($context, $entry);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unique_num_dup_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $dest
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'array_unique_num_dup_found'
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'array_unique_num_dup_done');
        $head = BasicBlockHelper::append($context, 'array_unique_num_dup_head');
        $body = BasicBlockHelper::append($context, 'array_unique_num_dup_body');
        $foundBlock = BasicBlockHelper::append($context, 'array_unique_num_dup_found_block');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $candEntry = self::listEntryAt($context, $dest, $idx);
        $candNum = self::valueEntryToNumericDouble($context, $candEntry);
        $match = $context->builder->fcmp(Builder::REAL_OEQ, $candNum, $needleNum);
        $continueBlock = BasicBlockHelper::append($context, 'array_unique_num_dup_continue');
        $context->builder->branchIf($match, $foundBlock, $continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /** Coerce boxed __value__* to double for array_unique SORT_NUMERIC (numeric_compare_function). */
    private static function valueEntryToNumericDouble(Context $context, Value $entry): Value
    {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $numSlot = $context->builder->alloca($double, 1, 'array_unique_num_scalar');
        $context->builder->store($zero, $numSlot);

        $longBlock = BasicBlockHelper::append($context, 'array_unique_num_long');
        $dblBlock = BasicBlockHelper::append($context, 'array_unique_num_double');
        $stringBlock = BasicBlockHelper::append($context, 'array_unique_num_string');
        $boolBlock = BasicBlockHelper::append($context, 'array_unique_num_bool');
        $nullBlock = BasicBlockHelper::append($context, 'array_unique_num_null');
        $defaultBlock = BasicBlockHelper::append($context, 'array_unique_num_default');
        $mergeBlock = BasicBlockHelper::append($context, 'array_unique_num_merge');

        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'array_unique_num_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $context->builder->store($context->builder->sitofp($longVal, $double), $numSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'array_unique_num_after_double');
        $context->builder->branchIf($isDouble, $dblBlock, $afterDouble);

        $context->builder->positionAtEnd($dblBlock);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry),
            $numSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterDouble);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, 'array_unique_num_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $context->builder->store(self::stringPtrToDouble($context, $strPtr), $numSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'array_unique_num_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $context->builder->store(
            $context->builder->uitofp($boolVal, $double),
            $numSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterBool);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $defaultBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($numSlot);
    }

    /**
     * Append a packed list entry (int or string) to dest; preserves count() vs sparse setLongAt.
     */
    private static function appendListEntryScalars(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest
    ): void {
        $tag = 'n'.self::$copyListEntrySeq++;
        $srcEntry = self::listEntryAt($context, $src, $srcIndex);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'ht_unique_append_long_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'ht_unique_append_string_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_unique_append_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'ht_unique_append_after_string_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        self::appendElement(
            $context,
            $dest,
            new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        self::appendElement(
            $context,
            $dest,
            new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry)
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * array_diff() for arrays of scalar values (loose compare; subset of PHP; issue #1206).
     */
    public static function arrayDiff(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayDiffHashTable($context, $src, $otherHts);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayDiffHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $loose = $context->constantFromBool(false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_diff_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_diff_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_diff_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_diff_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_diff_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_diff_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_diff_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_diff_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inOthers = self::entryInAnyHaystack($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inOthers, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_diff_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_diff_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_diff_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_diff_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_diff_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_diff_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_diff_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_diff_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $inOthers = self::entryInAnyHaystack($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inOthers, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAnyHaystack(
        Context $context,
        Value $entry,
        array $otherHts,
        Value $strict
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $foundSlot = $context->builder->alloca($i1, 1, 'array_diff_in_others');
        $context->builder->store($falseVal, $foundSlot);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $stringBlock = BasicBlockHelper::append($context, 'array_diff_in_others_string');
        $longBlock = BasicBlockHelper::append($context, 'array_diff_in_others_long');
        $falseBlock = BasicBlockHelper::append($context, 'array_diff_in_others_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_diff_in_others_merge');

        $afterString = BasicBlockHelper::append($context, 'array_diff_in_others_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::entryInAnyHaystackNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::entryInAnyHaystackNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAnyHaystackNeedle(
        Context $context,
        Variable $needle,
        array $otherHts,
        Value $strict
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'array_diff_in_others_needle');
        $context->builder->store($i1->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'array_diff_in_others_needle_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'array_diff_in_others_needle_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'array_diff_in_others_needle_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'array_diff_in_others_needle_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $haystack = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $otherHts[$i]
            );
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::inArray($context, $needle, $haystack, $strict, 'diff'.$i);
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);
            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * array_replace_recursive() — nested key merge (ext/standard/array.c parity; #3166).
     */
    public static function arrayReplaceRecursive(Context $context, Variable $first, Variable ...$others): Value
    {
        $result = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $result, self::loadHashTable($context, $first));
        foreach ($others as $other) {
            $otherHt = self::loadHashTable($context, $other);
            self::replaceRecursiveOverlayPackedIndices($context, $result, $otherHt);
            self::replaceRecursiveMergeStringKeys($context, $result, $otherHt);
            self::replaceRecursiveAddMissingStringKeys($context, $result, $otherHt);
        }

        return $result;
    }

    /**
     * Packed-index overlay for array_replace_recursive() (#3166).
     */
    private static function replaceRecursiveOverlayPackedIndices(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $htType = Variable::TYPE_HASHTABLE;

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_replace_rec_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_replace_rec_packed_head');
        $body = BasicBlockHelper::append($context, 'array_replace_rec_packed_body');
        $set = BasicBlockHelper::append($context, 'array_replace_rec_packed_set');
        $next = BasicBlockHelper::append($context, 'array_replace_rec_packed_next');
        $done = BasicBlockHelper::append($context, 'array_replace_rec_packed_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $set, $next);

        $context->builder->positionAtEnd($set);
        $destHas = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $srcVal = self::listEntryAt($context, $src, $idx);
        $srcIsHt = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($srcVal, $valueMap['type'])),
            $i8->constInt($htType, false)
        );
        $destVal = self::listEntryAt($context, $dest, $idx);
        $destIsHt = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($destVal, $valueMap['type'])),
            $i8->constInt($htType, false)
        );
        $bothHt = $context->builder->and(
            $destHas,
            $context->builder->and($srcIsHt, $destIsHt)
        );
        $copy = BasicBlockHelper::append($context, 'array_replace_rec_packed_copy');
        $merge = BasicBlockHelper::append($context, 'array_replace_rec_packed_merge');
        $context->builder->branchIf($bothHt, $merge, $copy);

        $context->builder->positionAtEnd($copy);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($merge);
        $existingHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $destVal
        );
        $overlayHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcVal
        );
        $merged = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $merged, $existingHt);
        self::replaceRecursiveAddMissingStringKeys($context, $merged, $overlayHt);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $dest,
            $idx,
            $merged
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Merge existing string keys when both values are hashtables (VM in-place parity; #3166).
     */
    private static function replaceRecursiveMergeStringKeys(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $valuePtrType = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $htType = Variable::TYPE_HASHTABLE;

        $strInit = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_head');
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_replace_rec_merge_str_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $existingPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $keyStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existingPtr, $valuePtrType->constNull());
        $skip = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_skip');
        $replace = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_replace');
        $context->builder->branchIf($existingNull, $skip, $replace);

        $context->builder->positionAtEnd($replace);
        $srcIsHt = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($valEntry, $valueMap['type'])),
            $i8->constInt($htType, false)
        );
        $existingIsHt = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->structGep($existingPtr, $valueMap['type'])),
            $i8->constInt($htType, false)
        );
        $bothHt = $context->builder->and($srcIsHt, $existingIsHt);
        $scalarReplace = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_scalar');
        $deepMerge = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_deep');
        $context->builder->branchIf($bothHt, $deepMerge, $scalarReplace);

        $context->builder->positionAtEnd($scalarReplace);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($deepMerge);
        $existingHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $existingPtr
        );
        $overlayHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valEntry
        );
        self::replaceRecursiveAddMissingStringKeys($context, $existingHt, $overlayHt);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Add string keys from {@param $src} missing in {@param $dest} (#3166).
     */
    private static function replaceRecursiveAddMissingStringKeys(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $valuePtrType = $context->getTypeFromString('__value__*');

        $strInit = BasicBlockHelper::append($context, 'array_replace_rec_add_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_rec_add_str_head');
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_replace_rec_add_str_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_rec_add_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_rec_add_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_rec_add_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_rec_add_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $existingPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $keyStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existingPtr, $valuePtrType->constNull());
        $doSet = BasicBlockHelper::append($context, 'array_replace_rec_add_str_do_set');
        $context->builder->branchIf($existingNull, $doSet, $strNext);

        $context->builder->positionAtEnd($doSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Array union ($left + $right): left keys win; integer keys are not renumbered (#3690, #5032).
     *
     * @see Zend/zend_operators.c add_function() / zend_hash_merge()
     */
    public static function arrayUnion(Context $context, Variable $left, Variable $right): Variable
    {
        $leftHt = self::loadHashTable($context, $left);
        $rightHt = self::loadHashTable($context, $right);
        $dest = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $dest, $leftHt);
        self::unionInPlaceMissingKeys($context, $dest, $rightHt);
        $context->refcount->addref($dest);

        if (Variable::TYPE_HASHTABLE === $left->type && Variable::TYPE_HASHTABLE === $right->type) {
            return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $dest);
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $dest
        );
        $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $var->valueBoxHashtable = true;

        return $var;
    }

    /**
     * Append keys from $src that are absent in $dest (Zend array union / += semantics).
     */
    private static function unionInPlaceMissingKeys(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_union_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_union_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_union_packed_body');
        $packedCheck = BasicBlockHelper::append($context, 'array_union_packed_check');
        $packedCopy = BasicBlockHelper::append($context, 'array_union_packed_copy');
        $packedSkip = BasicBlockHelper::append($context, 'array_union_packed_skip');
        $packedNext = BasicBlockHelper::append($context, 'array_union_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_union_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $srcSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($srcSet, $packedCheck, $packedNext);

        $context->builder->positionAtEnd($packedCheck);
        $destSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $context->builder->branchIf($destSet, $packedSkip, $packedCopy);

        $context->builder->positionAtEnd($packedCopy);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_union_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_union_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_union_str_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_union_str_body');
        $strCheck = BasicBlockHelper::append($context, 'array_union_str_check');
        $strSet = BasicBlockHelper::append($context, 'array_union_str_set');
        $strSkip = BasicBlockHelper::append($context, 'array_union_str_skip');
        $strNext = BasicBlockHelper::append($context, 'array_union_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_union_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strCheck);

        $context->builder->positionAtEnd($strCheck);
        $exists = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $keyStr
        );
        $context->builder->branchIf($exists, $strSkip, $strSet);

        $context->builder->positionAtEnd($strSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * array_replace() for arrays with int and string keys (subset of PHP; issue #1208).
     */
    public static function arrayReplace(Context $context, Variable $first, Variable ...$others): Value
    {
        $dest = HashTableHelper::alloc($context);
        foreach ([$first, ...$others] as $array) {
            self::overlayHashTable($context, $dest, self::loadHashTable($context, $array));
        }

        return $dest;
    }

    /**
     * array_replace_key() — copy first array, replace values only for keys that already exist (#5650).
     */
    public static function arrayReplaceKey(Context $context, Variable $first, Variable $replacements): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $dest, self::loadHashTable($context, $first));
        self::overlayExistingKeysOnly($context, $dest, self::loadHashTable($context, $replacements));

        return $dest;
    }

    private static function overlayExistingKeysOnly(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_replace_key_overlay_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_body');
        $packedSet = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_set');
        $packedNext = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedSet, $packedNext);

        $context->builder->positionAtEnd($packedSet);
        $destHas = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $packedCopy = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_copy');
        $packedSkip = BasicBlockHelper::append($context, 'array_replace_key_overlay_packed_skip');
        $context->builder->branchIf($destHas, $packedCopy, $packedSkip);

        $context->builder->positionAtEnd($packedCopy);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_replace_key_overlay_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $destHasKey = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $keyStr
        );
        $strCopy = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_copy');
        $strSkip = BasicBlockHelper::append($context, 'array_replace_key_overlay_str_skip');
        $context->builder->branchIf($destHasKey, $strCopy, $strSkip);

        $context->builder->positionAtEnd($strCopy);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    private static function overlayHashTable(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_replace_overlay_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_replace_overlay_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_replace_overlay_packed_body');
        $packedSet = BasicBlockHelper::append($context, 'array_replace_overlay_packed_set');
        $packedNext = BasicBlockHelper::append($context, 'array_replace_overlay_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_replace_overlay_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedSet, $packedNext);

        $context->builder->positionAtEnd($packedSet);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_replace_overlay_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_overlay_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_replace_overlay_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_overlay_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_overlay_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_overlay_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_overlay_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * array_intersect() for arrays of scalar values (loose compare; issue #1207).
     */
    public static function arrayIntersect(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayIntersectHashTable($context, $src, $otherHts);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayIntersectHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $loose = $context->constantFromBool(false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_intersect_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_intersect_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_intersect_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_intersect_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_intersect_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_intersect_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_intersect_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_intersect_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inAll = self::entryInAllHaystacks($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inAll, $packedAdd, $packedSkip);

        $context->builder->positionAtEnd($packedAdd);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $idx,
            self::listEntryAt($context, $src, $idx)
        );
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_intersect_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_intersect_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_intersect_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_intersect_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_intersect_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_intersect_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_intersect_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_intersect_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $inAll = self::entryInAllHaystacks($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inAll, $strAdd, $strSkip);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAllHaystacks(
        Context $context,
        Value $entry,
        array $otherHts,
        Value $strict
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $foundSlot = $context->builder->alloca($i1, 1, 'array_intersect_in_all');
        $context->builder->store($falseVal, $foundSlot);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $stringBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_string');
        $longBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_long');
        $falseBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_merge');

        $afterString = BasicBlockHelper::append($context, 'array_intersect_in_all_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::entryInAllHaystacksNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::entryInAllHaystacksNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAllHaystacksNeedle(
        Context $context,
        Variable $needle,
        array $otherHts,
        Value $strict
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $n = \count($otherHts);
        if (0 === $n) {
            return $i1->constInt(1, false);
        }

        $resultSlot = $context->builder->alloca($i1, 1, 'array_intersect_in_all_needle_result');
        $failBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_fail');
        $okBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_merge');
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'array_intersect_in_all_needle_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $haystack = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $otherHts[$i]
            );
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::inArray($context, $needle, $haystack, $strict, 'intersect'.$i);
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * array_diff_assoc() — strict key+value compare (php-src ext/standard/array.c, #3129).
     */
    public static function arrayDiffAssoc(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayDiffAssocHashTable($context, $src, $otherHts);
    }

    /**
     * array_intersect_assoc() — strict key+value compare (php-src ext/standard/array.c, #3129).
     */
    public static function arrayIntersectAssoc(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayIntersectAssocHashTable($context, $src, $otherHts);
    }

    /**
     * array_diff_key() — key-only diff (php-src ext/standard/array.c, #4188).
     */
    public static function arrayDiffKey(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayDiffKeyHashTable($context, $src, $otherHts);
    }

    /**
     * array_intersect_key() — key-only intersect (php-src ext/standard/array.c, #4188).
     */
    public static function arrayIntersectKey(Context $context, Variable $first, Variable ...$others): Value
    {
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayIntersectKeyHashTable($context, $src, $otherHts);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayDiffAssocHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_diff_assoc_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_diff_assoc_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_diff_assoc_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_diff_assoc_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_diff_assoc_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_diff_assoc_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_diff_assoc_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_diff_assoc_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inOthers = self::pairInAnyHaystacksPacked($context, $idx, $valEntry, $otherHts);
        $context->builder->branchIf($inOthers, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_diff_assoc_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_diff_assoc_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_diff_assoc_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_diff_assoc_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_diff_assoc_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_diff_assoc_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_diff_assoc_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_diff_assoc_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $inOthers = self::pairInAnyHaystacksString($context, $keyStr, $valEntry, $otherHts);
        $context->builder->branchIf($inOthers, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayIntersectAssocHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_intersect_assoc_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_intersect_assoc_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inAll = self::pairInAllHaystacksPacked($context, $idx, $valEntry, $otherHts);
        $context->builder->branchIf($inAll, $packedAdd, $packedSkip);

        $context->builder->positionAtEnd($packedAdd);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $idx,
            self::listEntryAt($context, $src, $idx)
        );
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_intersect_assoc_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_intersect_assoc_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_intersect_assoc_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_intersect_assoc_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_intersect_assoc_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_intersect_assoc_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_intersect_assoc_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_intersect_assoc_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $inAll = self::pairInAllHaystacksString($context, $keyStr, $valEntry, $otherHts);
        $context->builder->branchIf($inAll, $strAdd, $strSkip);

        $context->builder->positionAtEnd($strAdd);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayDiffKeyHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_diff_key_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_diff_key_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_diff_key_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_diff_key_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_diff_key_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_diff_key_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_diff_key_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_diff_key_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $inOthers = self::keyInAnyHaystacksPacked($context, $idx, $otherHts);
        $context->builder->branchIf($inOthers, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_diff_key_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_diff_key_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_diff_key_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_diff_key_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_diff_key_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_diff_key_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_diff_key_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_diff_key_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $inOthers = self::keyInAnyHaystacksString($context, $keyStr, $otherHts);
        $context->builder->branchIf($inOthers, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayIntersectKeyHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_intersect_key_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_intersect_key_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_intersect_key_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_intersect_key_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_intersect_key_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_intersect_key_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_intersect_key_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_intersect_key_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $inAll = self::keyInAllHaystacksPacked($context, $idx, $otherHts);
        $context->builder->branchIf($inAll, $packedAdd, $packedSkip);

        $context->builder->positionAtEnd($packedAdd);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $idx,
            self::listEntryAt($context, $src, $idx)
        );
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_intersect_key_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_intersect_key_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_intersect_key_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_intersect_key_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_intersect_key_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_intersect_key_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_intersect_key_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_intersect_key_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $inAll = self::keyInAllHaystacksString($context, $keyStr, $otherHts);
        $context->builder->branchIf($inAll, $strAdd, $strSkip);

        $context->builder->positionAtEnd($strAdd);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    private static function entriesLooseEqual(Context $context, Value $leftEntry, Value $rightEntry): Value
    {
        $left = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $leftEntry
        );
        $right = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $rightEntry
        );

        return JitValueCompare::looseEqualValueToValue($context, $left, $right);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInHaystackPacked(
        Context $context,
        Value $idx,
        Value $valEntry,
        Value $haystack
    ): Value {
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $haystack,
            $idx
        );
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $resultSlot = $context->builder->alloca($i1, 1, 'pair_in_ht_packed');
        $context->builder->store($falseVal, $resultSlot);

        $notSet = BasicBlockHelper::append($context, 'pair_in_ht_packed_not_set');
        $compare = BasicBlockHelper::append($context, 'pair_in_ht_packed_compare');
        $done = BasicBlockHelper::append($context, 'pair_in_ht_packed_done');
        $context->builder->branchIf($isSet, $compare, $notSet);

        $context->builder->positionAtEnd($compare);
        $otherEntry = self::listEntryAt($context, $haystack, $idx);
        $context->builder->store(
            self::entriesLooseEqual($context, $valEntry, $otherEntry),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($notSet);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInHaystackString(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        Value $haystack
    ): Value {
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $haystack,
            $keyStr
        );
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $resultSlot = $context->builder->alloca($i1, 1, 'pair_in_ht_string');
        $context->builder->store($falseVal, $resultSlot);

        $notSet = BasicBlockHelper::append($context, 'pair_in_ht_string_not_set');
        $compare = BasicBlockHelper::append($context, 'pair_in_ht_string_compare');
        $done = BasicBlockHelper::append($context, 'pair_in_ht_string_done');
        $context->builder->branchIf($isSet, $compare, $notSet);

        $context->builder->positionAtEnd($compare);
        $otherEntry = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $haystack,
            $keyStr
        );
        $context->builder->store(
            self::entriesLooseEqual($context, $valEntry, $otherEntry),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($notSet);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInAnyHaystacksPacked(
        Context $context,
        Value $idx,
        Value $valEntry,
        array $otherHts
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'pair_in_any_packed');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $done = BasicBlockHelper::append($context, 'pair_in_any_packed_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_any_packed_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_any_packed_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'pair_in_any_packed_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::pairInHaystackPacked($context, $idx, $valEntry, $otherHts[$i]);
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);

            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);

            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInAllHaystacksPacked(
        Context $context,
        Value $idx,
        Value $valEntry,
        array $otherHts
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $i1 = $context->getTypeFromString('int1');
        $resultSlot = $context->builder->alloca($i1, 1, 'pair_in_all_packed');
        $failBlock = BasicBlockHelper::append($context, 'pair_in_all_packed_fail');
        $okBlock = BasicBlockHelper::append($context, 'pair_in_all_packed_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'pair_in_all_packed_merge');
        $n = \count($otherHts);
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_all_packed_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'pair_in_all_packed_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::pairInHaystackPacked($context, $idx, $valEntry, $otherHts[$i]);
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInAnyHaystacksString(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        array $otherHts
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'pair_in_any_string');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $done = BasicBlockHelper::append($context, 'pair_in_any_string_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_any_string_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_any_string_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'pair_in_any_string_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::pairInHaystackString($context, $keyStr, $valEntry, $otherHts[$i]);
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);

            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);

            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function pairInAllHaystacksString(
        Context $context,
        Value $keyStr,
        Value $valEntry,
        array $otherHts
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $i1 = $context->getTypeFromString('int1');
        $resultSlot = $context->builder->alloca($i1, 1, 'pair_in_all_string');
        $failBlock = BasicBlockHelper::append($context, 'pair_in_all_string_fail');
        $okBlock = BasicBlockHelper::append($context, 'pair_in_all_string_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'pair_in_all_string_merge');
        $n = \count($otherHts);
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'pair_in_all_string_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'pair_in_all_string_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::pairInHaystackString($context, $keyStr, $valEntry, $otherHts[$i]);
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function keyInAnyHaystacksPacked(Context $context, Value $idx, array $otherHts): Value
    {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'key_in_any_packed');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $done = BasicBlockHelper::append($context, 'key_in_any_packed_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'key_in_any_packed_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'key_in_any_packed_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'key_in_any_packed_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $otherHts[$i],
                $idx
            );
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);

            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);

            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function keyInAllHaystacksPacked(Context $context, Value $idx, array $otherHts): Value
    {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $i1 = $context->getTypeFromString('int1');
        $resultSlot = $context->builder->alloca($i1, 1, 'key_in_all_packed');
        $failBlock = BasicBlockHelper::append($context, 'key_in_all_packed_fail');
        $okBlock = BasicBlockHelper::append($context, 'key_in_all_packed_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'key_in_all_packed_merge');
        $n = \count($otherHts);
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'key_in_all_packed_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'key_in_all_packed_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $otherHts[$i],
                $idx
            );
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function keyInAnyHaystacksString(Context $context, Value $keyStr, array $otherHts): Value
    {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'key_in_any_string');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $done = BasicBlockHelper::append($context, 'key_in_any_string_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'key_in_any_string_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'key_in_any_string_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'key_in_any_string_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $otherHts[$i],
                $keyStr
            );
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);

            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);

            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function keyInAllHaystacksString(Context $context, Value $keyStr, array $otherHts): Value
    {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $i1 = $context->getTypeFromString('int1');
        $resultSlot = $context->builder->alloca($i1, 1, 'key_in_all_string');
        $failBlock = BasicBlockHelper::append($context, 'key_in_all_string_fail');
        $okBlock = BasicBlockHelper::append($context, 'key_in_all_string_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'key_in_all_string_merge');
        $n = \count($otherHts);
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'key_in_all_string_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'key_in_all_string_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $otherHts[$i],
                $keyStr
            );
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }
}
