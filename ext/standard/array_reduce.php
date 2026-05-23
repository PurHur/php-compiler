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
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_reduce() with string builtin callbacks (subset of PHP).
 *
 * JIT/AOT: deferred until array_reduce lowering lands; VM supports pow, hypot, fmod, atan2.
 * Closures and other callables are deferred — see ArrayReduceCallbackPolicy (#1213).
 */
final class array_reduce extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_reduce() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_reduce() first argument must be an array in this compiler build');
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (!ArrayReduceCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayReduceCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalReduce::resolveStringCallback($callback->toString());
        $src = $array->toArray();
        $hasInitial = 3 === $argc;
        $initial = $hasInitial ? $frame->calledArgs[2]->resolveIndirect() : null;
        $first = true;
        $carry = null;
        foreach ($src->iterateKeyed(true) as [, $value]) {
            if ($first) {
                $first = false;
                if ($hasInitial) {
                    $carry = new Variable();
                    $carry->copyFrom($initial);
                } else {
                    $carry = new Variable();
                    $carry->copyFrom($value);
                    continue;
                }
            }
            $carry = VmInternalReduce::invoke($fn, $carry, $value);
        }
        if ($first) {
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
        throw new \LogicException(
            'array_reduce() is not implemented for JIT/AOT in this compiler build; use the VM path'
        );
    }
}
