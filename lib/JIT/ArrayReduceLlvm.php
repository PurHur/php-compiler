<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmInternalCall;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_reduce(Closure|user-fn string) for thin standalone AOT (#24156 / #33721).
 *
 * NestedJIT of a helper that calls VmClosureInvoke fails with zero Closure candidates
 * when the user module only has string callbacks (#33721). Closures and user-function
 * names therefore lower here; stdlib string builtins stay on NestedJIT reduceWithBuiltin.
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

        return self::reducePacked(
            $context,
            $ht,
            $initialPtr,
            static function (Context $ctx, Variable $carryVar, Variable $elem) use ($closure): Value {
                return $closure->closureCall->call($ctx, $carryVar, $elem);
            },
            'array_reduce_llvm'
        );
    }

    /**
     * Compile-time string stdlib builtin (intval, …) (#33721).
     *
     * @param Value $initialPtr {@see __value__*} initial (TYPE_NULL when omitted)
     * @return Value {@see __value__*} carry result
     */
    public static function reduceWithBuiltin(
        Context $context,
        Value $ht,
        string $builtinName,
        Value $initialPtr
    ): Value {
        $handler = VmInternalCall::resolveStringCallback($builtinName);

        return self::reducePacked(
            $context,
            $ht,
            $initialPtr,
            static function (Context $ctx, Variable $carryVar, Variable $elem) use ($handler): Value {
                return $handler->call($ctx, $carryVar, $elem);
            },
            'array_reduce_builtin'
        );
    }

    /**
     * Compile-time string user-function name in this TU (#33721).
     *
     * @param Value $initialPtr {@see __value__*} initial (TYPE_NULL when omitted)
     * @return Value {@see __value__*} carry result
     */
    public static function reduceWithUserFunction(
        Context $context,
        Value $ht,
        string $functionName,
        Value $initialPtr
    ): Value {
        $proxy = $context->resolveFunctionProxy(strtolower(ltrim($functionName, '\\')));

        return self::reducePacked(
            $context,
            $ht,
            $initialPtr,
            static function (Context $ctx, Variable $carryVar, Variable $elem) use ($proxy): Value {
                return $proxy->call($ctx, $carryVar, $elem);
            },
            'array_reduce_user'
        );
    }

    /**
     * @param callable(Context, Variable, Variable): Value $invoke carry+elem → raw result
     * @param Value $initialPtr {@see __value__*}
     * @return Value {@see __value__*}
     */
    private static function reducePacked(
        Context $context,
        Value $ht,
        Value $initialPtr,
        callable $invoke,
        string $prefix
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, $prefix.'_cont');
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
        // Skip TYPE_UNDEFINED holes only — TYPE_NULL is a real value (#33710 / #33705).
        $isUndef = HashTableHelper::packedIndexIsUndefined($context, $ht, $i);
        $context->builder->branchIf($isUndef, $advance, $body);

        $context->builder->positionAtEnd($body);
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $i);
        $carryVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $carryPtr);
        $raw = $invoke($context, $carryVar, $elem);
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
