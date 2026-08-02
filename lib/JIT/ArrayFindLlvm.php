<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_find family (Closure) for thin standalone AOT (#26824).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFindJitHelper} compiles after ternary
 * ARG_SEND fixes, but solo NestedJIT stubs VmClosureCall / HashTable walk → null/false
 * under user-script AOT (peer ArrayReduceLlvm #24156 / Levenshtein #26830).
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
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($atEnd, $done, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $i
        );
        $context->builder->branchIf($isSet, $body, $advance);

        $context->builder->positionAtEnd($body);
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $i);
        $raw = (new NestedClosureInvoke())->call($context, $closure, $elem);
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
            $i64 = $context->builder->zExt($i, $context->getTypeFromString('int64'));
            JitValueBox::writeLong($context, $outSlot, $i64);
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

        $context->builder->positionAtEnd($done);

        return $context->builder->pointerCast($outPtr, $valuePtrTy);
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
