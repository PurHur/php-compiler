<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_find family (Closure) for thin standalone AOT (#26824, #27296).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFindJitHelper} compiles after ternary
 * ARG_SEND fixes, but solo NestedJIT stubs VmClosureCall / HashTable walk → null/false
 * under user-script AOT (peer ArrayReduceLlvm #24156 / Levenshtein #26830).
 *
 * Walk packed slots then {@see __strkey_node__} chains — peer {@see ArrayWalkLlvm} /
 * {@see HashTableValuesLlvm}. Indexed-only `nextFreeElement` walks miss string keys
 * (array_find_key → NULL, array_any → false; #27296).
 *
 * php-src: ext/standard/array.c — php_array_find / php_array_find_key / php_array_any / php_array_all
 */
final class ArrayFindLlvm
{
    public const MODE_FIND = 0;

    public const MODE_FIND_KEY = 1;

    public const MODE_ANY = 2;

    public const MODE_ALL = 3;

    /**
     * @return Value {@see __value__*} — found value/key, null, or boxed bool for any/all
     */
    public static function walkWithClosure(
        Context $context,
        Value $ht,
        Variable $closure,
        int $mode,
    ): Value {
        if (null === $closure->closureCall) {
            throw new \LogicException(
                'ArrayFindLlvm::walkWithClosure requires Variable::$closureCall (#26824); got type='
                .Variable::getStringType($closure->type)
            );
        }
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_find_llvm_cont');

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $valueTy = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));

        $outSlot = BasicBlockHelper::entryAlloca($context, $valueTy);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        if (self::MODE_ALL === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(true));
        } elseif (self::MODE_ANY === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(false));
        } else {
            $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
        }

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $prefix = 'array_find_llvm_m'.$mode;
        $head = BasicBlockHelper::append($context, $prefix.'_head');
        $check = BasicBlockHelper::append($context, $prefix.'_check');
        $body = BasicBlockHelper::append($context, $prefix.'_body');
        $advance = BasicBlockHelper::append($context, $prefix.'_adv');
        $strHead = BasicBlockHelper::append($context, $prefix.'_str_head');
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        // Exhaust packed walk → string-key chain (#27296); early match/fail still → $done.
        $context->builder->branchIf($atEnd, $strHead, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $i
        );
        $context->builder->branchIf($isSet, $body, $advance);

        $context->builder->positionAtEnd($body);
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $i);
        $keySlot = JitValueBox::alloc($context);
        $keyLong = $context->builder->zExt($i, $i64);
        JitValueBox::writeLong($context, $keySlot, $keyLong);
        $keyVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $keySlot);
        $raw = (new NestedClosureInvoke())->call($context, $closure, $elem, $keyVar);
        $resultPtr = self::boxResult($context, $raw);
        $matches = $context->castToBool($resultPtr);

        $matchBb = BasicBlockHelper::append($context, $prefix.'_match');
        $noMatchBb = BasicBlockHelper::append($context, $prefix.'_nomatch');
        $context->builder->branchIf($matches, $matchBb, $noMatchBb);

        $context->builder->positionAtEnd($matchBb);
        if (self::MODE_ANY === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(true));
            $context->builder->branch($done);
        } elseif (self::MODE_ALL === $mode) {
            $context->builder->branch($advance);
        } elseif (self::MODE_FIND_KEY === $mode) {
            JitValueBox::writeLong($context, $outSlot, $keyLong);
            $context->builder->branch($done);
        } else {
            JitValueBox::copyFromPointer(
                $context,
                $outSlot,
                JitValueBox::pointer($context, $elem->value)
            );
            $context->builder->branch($done);
        }

        $context->builder->positionAtEnd($noMatchBb);
        if (self::MODE_ALL === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(false));
            $context->builder->branch($done);
        } else {
            $context->builder->branch($advance);
        }

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        self::emitStringKeyWalk($context, $ht, $closure, $mode, $outSlot, $strHead, $done, $prefix);

        $context->builder->positionAtEnd($done);

        return $context->builder->pointerCast($outPtr, $valuePtrTy);
    }

    /**
     * Walk {@see __hashtable__::$strKeys} after packed slots (insertion order for string-only).
     */
    private static function emitStringKeyWalk(
        Context $context,
        Value $ht,
        Variable $closure,
        int $mode,
        Value $outSlot,
        $strHead,
        $done,
        string $prefix,
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $context->builder->positionAtEnd($strHead);
        $headNode = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $strCheck = BasicBlockHelper::append($context, $prefix.'_str_check');
        $strBody = BasicBlockHelper::append($context, $prefix.'_str_body');
        $strAdv = BasicBlockHelper::append($context, $prefix.'_str_adv');
        $context->builder->branch($strCheck);

        $context->builder->positionAtEnd($strCheck);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);

        $keySlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $keySlot),
            $keyStr
        );
        $keyVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $keySlot);
        $raw = (new NestedClosureInvoke())->call($context, $closure, $elem, $keyVar);
        $resultPtr = self::boxResult($context, $raw);
        $matches = $context->castToBool($resultPtr);

        $matchBb = BasicBlockHelper::append($context, $prefix.'_str_match');
        $noMatchBb = BasicBlockHelper::append($context, $prefix.'_str_nomatch');
        $context->builder->branchIf($matches, $matchBb, $noMatchBb);

        $context->builder->positionAtEnd($matchBb);
        if (self::MODE_ANY === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(true));
            $context->builder->branch($done);
        } elseif (self::MODE_ALL === $mode) {
            $context->builder->branch($strAdv);
        } elseif (self::MODE_FIND_KEY === $mode) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $outSlot),
                $keyStr
            );
            $context->builder->branch($done);
        } else {
            JitValueBox::copyFromPointer(
                $context,
                $outSlot,
                JitValueBox::pointer($context, $valSlot)
            );
            $context->builder->branch($done);
        }

        $context->builder->positionAtEnd($noMatchBb);
        if (self::MODE_ALL === $mode) {
            JitValueBox::writeBool($context, $outSlot, $context->constantFromBool(false));
            $context->builder->branch($done);
        } else {
            $context->builder->branch($strAdv);
        }

        $context->builder->positionAtEnd($strAdv);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($strCheck);
    }

    private static function boxResult(Context $context, Value $raw): Value
    {
        $have = $context->getStringFromType($raw->typeOf());
        if ('__value__*' === $have) {
            return $raw;
        }
        if ('__value__' === $have) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'array_find_llvm_box_struct');
            $slot = BasicBlockHelper::entryAlloca($context, $raw->typeOf());
            $context->builder->store($raw, $slot);

            return JitValueBox::pointer($context, $slot);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_find_llvm_box');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if ('int64' === $have || 'int32' === $have || 'int1' === $have || 'bool' === $have) {
            if ('int1' === $have || 'bool' === $have) {
                JitValueBox::writeBool($context, $slot, $raw);

                return $ptr;
            }
            $long = 'int64' === $have
                ? $raw
                : $context->builder->sExt($raw, $context->getTypeFromString('int64'));
            $context->builder->call($context->lookupFunction('__value__writeLong'), $ptr, $long);

            return $ptr;
        }
        if ('double' === $have) {
            $context->builder->call($context->lookupFunction('__value__writeDouble'), $ptr, $raw);

            return $ptr;
        }

        return JitValueBox::coerceToValuePtrForStore($context, $raw);
    }
}
