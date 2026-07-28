<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_reduce(Closure) for thin standalone AOT (#24156 follow-up).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayReduceJitHelper} uses
 * {@see Call\RuntimeIndirectClosureCall} with module-wide candidates; with ≥3 Closures
 * that path intermittently `free(): invalid pointer` when ArrayMapLlvm is also linked.
 * Lower reduce in the user module with the caller's {@see Variable::$closureCall}.
 *
 * php-src: ext/standard/array.c — php_array_reduce()
 */
final class ArrayReduceLlvm
{
    /**
     * @param Value $initialPtr {@see __value__*} initial (TYPE_NULL when omitted)
     * @return Value {@see __value__*} carry result
     */
    public static function reduceWithClosure(
        Context $context,
        Value $ht,
        Variable $closure,
        Value $initialPtr
    ): Value {
        if (null === $closure->closureCall) {
            throw new \LogicException(
                'ArrayReduceLlvm::reduceWithClosure requires Variable::$closureCall (#24156); got type='
                .Variable::getStringType($closure->type)
            );
        }
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_reduce_llvm_cont');
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $valueTy = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));

        $carrySlot = BasicBlockHelper::entryAlloca($context, $valueTy);
        $carryPtr = JitValueBox::pointer($context, $carrySlot);
        JitValueBox::copyFromPointer($context, $carrySlot, $initialPtr);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $head = BasicBlockHelper::append($context, 'array_reduce_llvm_head');
        $check = BasicBlockHelper::append($context, 'array_reduce_llvm_check');
        $body = BasicBlockHelper::append($context, 'array_reduce_llvm_body');
        $advance = BasicBlockHelper::append($context, 'array_reduce_llvm_adv');
        $done = BasicBlockHelper::append($context, 'array_reduce_llvm_done');
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
        $carryVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $carryPtr);
        $raw = $closure->closureCall->call($context, $carryVar, $elem);
        $resultPtr = self::boxResult($context, $raw);
        JitValueBox::copyFromPointer($context, $carrySlot, $resultPtr);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->pointerCast($carryPtr, $valuePtrTy);
    }

    private static function boxResult(Context $context, Value $raw): Value
    {
        $have = $context->getStringFromType($raw->typeOf());
        if ('__value__*' === $have) {
            return $raw;
        }
        if ('__value__' === $have) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'array_reduce_llvm_box_struct');
            $slot = BasicBlockHelper::entryAlloca($context, $raw->typeOf());
            $context->builder->store($raw, $slot);

            return JitValueBox::pointer($context, $slot);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_reduce_llvm_box');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if ('int64' === $have || 'int32' === $have || 'int1' === $have) {
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
