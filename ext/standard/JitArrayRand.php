<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helpers for array_rand() (issue #2321). */
final class JitArrayRand
{
    private const EMPTY_ERROR = 'array_rand(): Argument #1 ($array) cannot be empty';

    private const NUM_RANGE_ERROR = 'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)';

    public static function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        if (ArrayBuiltinHelper::isNativeArray($args[0]->type)) {
            throw new \LogicException(
                'array_rand() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php, or build the list with [] append'
            );
        }
        $sizeT = $context->getTypeFromString('size_t');
        if (isset($args[1])) {
            JitInternalStrictArg::requireInt($context, $args[1], 'array_rand', 'num', 2);
            $num = JitLongArg::lower($context, $args[1], 'array_rand() num');
        } else {
            $num = $sizeT->constInt(1, false);
        }

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[0]);
        self::emitRuntimeValidation($context, ArrayBuiltinHelper::getNumElements($context, $ht), $num);
        $context->builder->call(
            $context->lookupFunction('__hashtable__arrayRandPacked'),
            $ht,
            $num,
            $resultPtr
        );

        return $resultPtr;
    }

    /**
     * Runtime guards (php-src ext/standard/array.c php_array_rand; issue #4198).
     */
    private static function emitRuntimeValidation(Context $context, Value $count, Value $num): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $empty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyOk = BasicBlockHelper::append($context, 'arrayrand_not_empty');
        $emptyErr = BasicBlockHelper::append($context, 'arrayrand_empty_err');
        $context->builder->branchIf($empty, $emptyErr, $emptyOk);
        $context->builder->positionAtEnd($emptyErr);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::EMPTY_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($emptyOk);

        $numLow = $context->builder->icmp(Builder::INT_SLT, $num, $one);
        $numHigh = $context->builder->icmp(Builder::INT_SGT, $num, $count);
        $numBad = $context->builder->or($numLow, $numHigh);
        $numOk = BasicBlockHelper::append($context, 'arrayrand_num_ok');
        $numErr = BasicBlockHelper::append($context, 'arrayrand_num_err');
        $context->builder->branchIf($numBad, $numErr, $numOk);
        $context->builder->positionAtEnd($numErr);
        TypeErrorRaise::emitValueError($context, self::NUM_RANGE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($numOk);
    }
}
