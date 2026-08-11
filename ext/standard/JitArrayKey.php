<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** @internal AOT standalone must not call unresolved __hashtable__* decls — use struct helpers (#4462). */

/** LLVM JIT helpers for array_key_first()/array_key_last(). */
final class JitArrayKey
{
    private const TYPE_ERROR = '%s(): Argument #1 ($array) must be of type array, %s given';

    public static function keyFirst(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return self::keyAtEnd($context, $array, true);
    }

    public static function keyLast(Context $context, JITVariable $array): Value
    {
        self::ensureLinked($context);

        return self::keyAtEnd($context, $array, false);
    }

    private static function ensureLinked(Context $context): void
    {
        TypeErrorRaise::ensureLinked($context);
    }

    /**
     * JIT/AOT runtime guard for array_key_first()/array_key_last().
     *
     * In this compiler, boxed TYPE_VALUE operands can contain arrays or other scalars; we must
     * reject non-arrays (especially null) to match Zend’s TypeError behavior.
     */
    public static function requireArrayArg(Context $context, JITVariable $array, string $fn): void
    {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $array);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($loaded, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $isArrayType = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            );
            // AOT standalone may pass array literals as boxed values whose type byte is not
            // yet TYPE_ARRAY; accept when a hashtable payload is present (#4462).
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $loaded
            );
            $hasHt = $context->builder->icmp(
                Builder::INT_NE,
                $ht,
                $ht->typeOf()->constNull()
            );
            $isArray = $context->builder->or($isArrayType, $hasHt);
            $okBlock = BasicBlockHelper::append($context, 'array_key_req_ok');
            $errBlock = BasicBlockHelper::append($context, 'array_key_req_err');
            $context->builder->branchIf($isArray, $okBlock, $errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitBoxedNonArrayTypeError($context, $fn, $typeByte, $loaded);
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        self::emitErrorAndAbort(
            $context,
            \sprintf(self::TYPE_ERROR, $fn, JitOperandTypeLabel::givenLabel($context, $array))
        );
    }

    private static function emitBoxedNonArrayTypeError(
        Context $context,
        string $fn,
        Value $typeByte,
        Value $valuePtr
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $nullBlock = BasicBlockHelper::append($context, 'array_key_req_null');
        $stringBlock = BasicBlockHelper::append($context, 'array_key_req_string');
        $objectBlock = BasicBlockHelper::append($context, 'array_key_req_object');
        $intBlock = BasicBlockHelper::append($context, 'array_key_req_int');
        $floatBlock = BasicBlockHelper::append($context, 'array_key_req_float');
        $boolBlock = BasicBlockHelper::append($context, 'array_key_req_bool');
        $mixedBlock = BasicBlockHelper::append($context, 'array_key_req_mixed');
        $afterNull = BasicBlockHelper::append($context, 'array_key_req_after_null');
        $afterString = BasicBlockHelper::append($context, 'array_key_req_after_string');
        $afterObject = BasicBlockHelper::append($context, 'array_key_req_after_object');
        $afterInt = BasicBlockHelper::append($context, 'array_key_req_after_int');
        $afterFloat = BasicBlockHelper::append($context, 'array_key_req_after_float');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'null'));

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);
        $context->builder->positionAtEnd($stringBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'string'));

        $context->builder->positionAtEnd($afterString);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);
        $context->builder->positionAtEnd($objectBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'object'));

        $context->builder->positionAtEnd($afterObject);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);
        $context->builder->positionAtEnd($intBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'int'));

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $afterFloat);
        $context->builder->positionAtEnd($floatBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'float'));

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_BOOLEAN, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $mixedBlock);
        // zend_execute.c — bool actuals print true/false, not bool (#30114 / #29097).
        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $trueBlock = BasicBlockHelper::append($context, 'array_key_req_true');
        $falseBlock = BasicBlockHelper::append($context, 'array_key_req_false');
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'true'));
        $context->builder->positionAtEnd($falseBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'false'));

        $context->builder->positionAtEnd($mixedBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'mixed'));
    }

    /**
     * Catchable under AOT try/catch; fatal when uncaught (#27472 / #27474).
     * Bare TypeErrorRaise + abort aborts exit 134 inside try — use ExceptionBridge.
     */
    private static function emitErrorAndAbort(Context $context, string $message): void
    {
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_key_te_cont');
    }

    private static function keyAtEnd(Context $context, JITVariable $array, bool $first): Value
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $zeroSize = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $num = ArrayBuiltinHelper::getNumElements($context, $ht);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroI64);

        $emptyBb = BasicBlockHelper::append($context, 'array_key_'.($first ? 'first' : 'last').'_empty');
        $workBb = BasicBlockHelper::append($context, 'array_key_'.($first ? 'first' : 'last').'_work');
        $doneBb = BasicBlockHelper::append($context, 'array_key_'.($first ? 'first' : 'last').'_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        // JitValueBox::alloc() already initializes TYPE_NULL (#4462 AOT standalone).
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zeroSize);
        $packedBb = BasicBlockHelper::append($context, 'array_key_'.($first ? 'first' : 'last').'_packed');
        $stringBb = BasicBlockHelper::append($context, 'array_key_'.($first ? 'first' : 'last').'_string');
        $context->builder->branchIf($hasPacked, $packedBb, $stringBb);

        $tag = $first ? 'first' : 'last';
        $context->builder->positionAtEnd($packedBb);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_key_'.$tag.'_idx');
        if ($first) {
            $context->builder->store($zeroSize, $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($nextFree, $one), $idxSlot);
        }
        $loopHead = BasicBlockHelper::append($context, 'array_key_'.$tag.'_head');
        $loopBody = BasicBlockHelper::append($context, 'array_key_'.$tag.'_body');
        $loopFound = BasicBlockHelper::append($context, 'array_key_'.$tag.'_found');
        $loopNext = BasicBlockHelper::append($context, 'array_key_'.$tag.'_next');
        $loopFail = BasicBlockHelper::append($context, 'array_key_'.$tag.'_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        if ($first) {
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
            $context->builder->branchIf($atEnd, $loopFail, $loopBody);
        } else {
            $atStart = $context->builder->icmp(Builder::INT_EQ, $idx, $zeroSize);
            $context->builder->branchIf($atStart, $loopFail, $loopBody);
        }

        $context->builder->positionAtEnd($loopBody);
        $present = self::offsetIsSetAt($context, $ht, $map, $idx, $nextFree, $i8);
        $context->builder->branchIf($present, $loopFound, $loopNext);

        $context->builder->positionAtEnd($loopFound);
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $i64)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopNext);
        if ($first) {
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        }
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopFail);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        if ($first) {
            $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_key_first_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_key_first_str_found');
            $context->builder->branchIf($headNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($strFound);
            $keyStr = $context->builder->load($context->builder->structGep($head, $nodeMap['key']));
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $resultPtr,
                $owned
            );
            $context->builder->branch($doneBb);
        } else {
            $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_key_last_walk');
            $lastSlot = $context->builder->alloca($nodePtrType, 1, 'array_key_last_node');
            $context->builder->store($head, $walkSlot);
            $context->builder->store($nodePtrType->constNull(), $lastSlot);
            $walkHead = BasicBlockHelper::append($context, 'array_key_last_walk_head');
            $walkBody = BasicBlockHelper::append($context, 'array_key_last_walk_body');
            $walkDone = BasicBlockHelper::append($context, 'array_key_last_walk_done');
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkHead);
            $walkNode = $context->builder->load($walkSlot);
            $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
            $context->builder->branchIf($walkEnd, $walkDone, $walkBody);

            $context->builder->positionAtEnd($walkBody);
            $context->builder->store($walkNode, $lastSlot);
            $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
            $context->builder->store($nextWalk, $walkSlot);
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkDone);
            $lastNode = $context->builder->load($lastSlot);
            $lastNull = $context->builder->icmp(Builder::INT_EQ, $lastNode, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_key_last_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_key_last_str_found');
            $context->builder->branchIf($lastNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($strFound);
            $keyStr = $context->builder->load($context->builder->structGep($lastNode, $nodeMap['key']));
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $resultPtr,
                $owned
            );
            $context->builder->branch($doneBb);
        }

        $context->builder->positionAtEnd($doneBb);

        return $resultPtr;
    }

    /** Mirrors HashTable::implementOffsetIsSet — inlined for AOT standalone (#4462). */
    private static function offsetIsSetAt(
        Context $context,
        Value $ht,
        array $map,
        Value $index,
        Value $nextFree,
        $i8
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $inRange = $context->builder->icmp(Builder::INT_ULT, $index, $nextFree);
        $okBb = BasicBlockHelper::append($context, 'array_key_offset_ok');
        $noBb = BasicBlockHelper::append($context, 'array_key_offset_no');
        $mergeBb = BasicBlockHelper::append($context, 'array_key_offset_merge');
        $context->builder->branchIf($inRange, $okBb, $noBb);
        $context->builder->positionAtEnd($noBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($okBb);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGEP($values, $index);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $set = $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $result = $context->builder->phi($i1);
        $result->addIncoming($set, $okBb);
        $result->addIncoming($i1->constInt(0, false), $noBb);

        return $result;
    }
}
