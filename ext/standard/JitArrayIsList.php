<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for array_is_list() — keys must be 0..count-1. */
final class JitArrayIsList
{
    public static function canLowerOperand(JITVariable $array): bool
    {
        return ($array->type & JITVariable::IS_NATIVE_ARRAY)
            || JITVariable::TYPE_HASHTABLE === $array->type
            || JITVariable::TYPE_VALUE === $array->type
            || JitValueBox::isValueOperand($array);
    }

    /** Unreachable bool after TypeError+abort (issue #4753). */
    public static function unreachableBool(Context $context): Value
    {
        return $context->constantFromBool(false);
    }

    public static function invoke(Context $context, JITVariable $array): Value
    {
        if (!self::canLowerOperand($array)) {
            return self::unreachableBool($context);
        }
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(true);
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return self::hashTableIsList($context, $ht);
    }

    public static function hashTableIsList(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zeroSize = $sizeT->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroSize);

        $resultSlot = $context->builder->alloca($i1, 1, 'array_is_list_result');
        $exitBb = BasicBlockHelper::append($context, 'array_is_list_exit');
        $emptyBb = BasicBlockHelper::append($context, 'array_is_list_empty');
        $checkBb = BasicBlockHelper::append($context, 'array_is_list_check');
        $context->builder->branchIf($isEmpty, $emptyBb, $checkBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store($context->constantFromBool(true), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($checkBb);
        $context->builder->store($context->constantFromBool(true), $resultSlot);
        $iSlot = $context->builder->alloca($i64, 1, 'array_is_list_i');
        $context->builder->store($zeroI64, $iSlot);

        $loopHead = BasicBlockHelper::append($context, 'array_is_list_head');
        $loopBody = BasicBlockHelper::append($context, 'array_is_list_body');
        $loopInc = BasicBlockHelper::append($context, 'array_is_list_inc');
        $loopFail = BasicBlockHelper::append($context, 'array_is_list_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $iAsSize = $context->builder->truncOrBitCast($i, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $iAsSize, $num);
        $context->builder->branchIf($atEnd, $exitBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $iAsSize
        );
        $context->builder->branchIf($present, $loopInc, $loopFail);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store($context->builder->addNoSignedWrap($i, $oneI64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopFail);
        $context->builder->store($context->constantFromBool(false), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);

        return $context->builder->load($resultSlot);
    }

    /** array_is_assoc() — non-empty and not a list (issue #7016). */
    public static function invokeAssoc(Context $context, JITVariable $array): Value
    {
        if (!self::canLowerOperand($array)) {
            return self::unreachableBool($context);
        }
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(false);
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return self::hashTableIsAssoc($context, $ht);
    }

    public static function hashTableIsAssoc(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zeroSize = $sizeT->constInt(0, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroSize);
        $isList = self::hashTableIsList($context, $ht);

        $resultSlot = $context->builder->alloca($i1, 1, 'array_is_assoc_result');
        $exitBb = BasicBlockHelper::append($context, 'array_is_assoc_exit');
        $falseBb = BasicBlockHelper::append($context, 'array_is_assoc_false');
        $checkBb = BasicBlockHelper::append($context, 'array_is_assoc_check');
        $context->builder->branchIf($isEmpty, $falseBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $context->builder->store($context->builder->not($isList), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->store($context->constantFromBool(false), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);

        return $context->builder->load($resultSlot);
    }

    /**
     * Packed list or numeric-string keys 0..n-1 (array_merge reindex path; #3607).
     */
    public static function hashTableIsReindexableList(Context $context, Value $ht): Value
    {
        $isList = self::hashTableIsList($context, $ht);
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zeroSize = $sizeT->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroSize);

        $resultSlot = $context->builder->alloca($i1, 1, 'array_reindexable_result');
        $exitBb = BasicBlockHelper::append($context, 'array_reindexable_exit');
        $listBb = BasicBlockHelper::append($context, 'array_reindexable_list');
        $emptyBb = BasicBlockHelper::append($context, 'array_reindexable_empty');
        $checkBb = BasicBlockHelper::append($context, 'array_reindexable_check');
        $context->builder->branchIf($isList, $listBb, $emptyBb);

        $context->builder->positionAtEnd($listBb);
        $context->builder->store($context->constantFromBool(true), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branchIf($isEmpty, $listBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $context->builder->store($context->constantFromBool(true), $resultSlot);
        $iSlot = $context->builder->alloca($i64, 1, 'array_reindexable_i');
        $context->builder->store($zeroI64, $iSlot);

        $loopHead = BasicBlockHelper::append($context, 'array_reindexable_head');
        $loopBody = BasicBlockHelper::append($context, 'array_reindexable_body');
        $loopInt = BasicBlockHelper::append($context, 'array_reindexable_int_ok');
        $loopStr = BasicBlockHelper::append($context, 'array_reindexable_str_try');
        $loopStrOk = BasicBlockHelper::append($context, 'array_reindexable_str_ok');
        $loopInc = BasicBlockHelper::append($context, 'array_reindexable_inc');
        $loopFail = BasicBlockHelper::append($context, 'array_reindexable_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $iAsSize = $context->builder->truncOrBitCast($i, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $iAsSize, $num);
        $context->builder->branchIf($atEnd, $exitBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $presentInt = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $iAsSize
        );
        $context->builder->branchIf($presentInt, $loopInt, $loopStr);

        $context->builder->positionAtEnd($loopInt);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopStr);
        $keyStr = JitNativeString::formatIndexKey($context, $i);
        $presentStr = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branchIf($presentStr, $loopStrOk, $loopFail);

        $context->builder->positionAtEnd($loopStrOk);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store($context->builder->addNoSignedWrap($i, $oneI64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopFail);
        $context->builder->store($context->constantFromBool(false), $resultSlot);
        $context->builder->branch($exitBb);

        $context->builder->positionAtEnd($exitBb);

        return $context->builder->load($resultSlot);
    }
}
