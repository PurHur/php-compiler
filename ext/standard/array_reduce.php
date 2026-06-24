<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_reduce() with string user-function and closure callbacks (subset of PHP).
 *
 * JIT/AOT: compile-time string user-function names in this compile unit (#1213).
 */
final class array_reduce extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_reduce() requires two or three arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_reduce() first argument must be an array in this compiler build');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_reduce() requires VM context in this compiler build');
        }
        $callback = $frame->calledArgs[1];
        $hasInitial = 3 === $argc;
        $initial = $hasInitial ? $frame->calledArgs[2]->resolveIndirect() : null;
        [$closure, $callbackFn] = VmReduceCallback::resolve($frame, $callback);
        if (null === $frame->returnVar) {
            return;
        }
        self::reduceVm($frame, $array, $hasInitial, $initial, $closure, $callbackFn, $frame->vmContext);
    }

    /**
     * @param ClosureState|Internal|Func\PHP|null $callbackFn
     */
    private static function reduceVm(
        Frame $frame,
        Variable $array,
        bool $hasInitial,
        ?Variable $initial,
        ?ClosureState $closureOrNull,
        Internal|Func\PHP|null $callbackFn,
        VmContext $context
    ): void {
        $carry = null;
        if ($hasInitial) {
            $carry = new Variable();
            $carry->copyFrom($initial);
        }
        $empty = true;
        foreach ($array->toArray()->iterateKeyed(true) as [, $value]) {
            $empty = false;
            $item = new Variable();
            $item->copyFrom($value);
            if ($hasInitial) {
                $carryArg = $carry;
            } elseif (null === $carry) {
                $carryArg = new Variable();
                $carryArg->null();
            } else {
                $carryArg = $carry;
            }
            if (null !== $closureOrNull) {
                $carry = VmClosureCall::invoke($context, $closureOrNull, $carryArg, $item);
                continue;
            }
            if ($callbackFn instanceof Internal) {
                $carry = VmInternalCall::invoke($callbackFn, $carryArg, $item);
                continue;
            }
            $carry = VmUserCall::invoke($context, $callbackFn, $carryArg, $item);
        }
        if ($empty) {
            if ($hasInitial) {
                $frame->returnVar->copyFrom($initial);
            } else {
                $frame->returnVar->null();
            }

            return;
        }
        $frame->returnVar->copyFrom($carry);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_reduce() requires two or three arguments in this compiler build');
        }
        if ($args[1]->isNullConstant) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (!ArrayReduceCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        $initial = 3 === $argc ? $args[2] : null;
        if (ArrayReduceCallbackPolicy::isClosureJitLowerable($args[1])) {
            return ArrayBuiltinHelper::buildReduceArrayWithClosure($context, $args[0], $args[1], $initial);
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_reduce() callback');
        }

        return ArrayBuiltinHelper::buildReduceArray($context, $args[0], $args[1], $initial);
    }
}
