<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\array_combine;
use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\JIT\Builtin\ArrayMapRuntime;
use PHPCompiler\JIT\Builtin\HashTableUnionRuntime;
use PHPCompiler\JIT\Builtin\SortRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ArrayBuiltinHelper
{
    /** Monotonic id so copyListEntry basic blocks stay unique per LLVM function. */
    private static int $copyListEntrySeq = 0;

    public static function isNativeArray(int $type): bool
    {
        return 0 !== ($type & Variable::IS_NATIVE_ARRAY);
    }

    /**
     * Empty packed hashtable for builtins that return [] (array_merge arity 0,
     * AOT realpath_cache_get empty snapshot, …).
     *
     * php-src: zend_array_dup / empty HashTable — {@see HashTableHelper::alloc()}.
     */
    public static function emptyArray(Context $context): Value
    {
        return HashTableHelper::alloc($context);
    }

    /**
     * Array union (`$a + $b`) for hashtable / value-boxed / native-list operands.
     *
     * php-src: Zend/zend_operators.c — add_function; left-hand keys win (#3690, #10533).
     * Bridge: {@see HashTableUnionRuntime} → {@see \PHPCompiler\VM\HashTable::unionCopy()}.
     */
    public static function arrayUnion(Context $context, Variable $left, Variable $right): Variable
    {
        $leftHt = self::loadHashTable($context, $left);
        $rightHt = self::loadHashTable($context, $right);
        $dest = HashTableUnionRuntime::union($context, $leftHt, $rightHt);
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

    /**
     * array_map() with multiple source arrays — null zip or closure (#4539, ext/standard/array.c).
     *
     * @param list<Variable> $arrays
     */
    public static function buildMapMultipleArrays(Context $context, Variable $callback, array $arrays): Value
    {
        if (Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return ArrayMapRuntime::mapNullZipMultiple($context, $arrays);
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
}
