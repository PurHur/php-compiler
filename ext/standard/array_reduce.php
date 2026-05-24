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
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_reduce() with string user-function callbacks (subset of PHP).
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
        if (null === $frame->vmContext) {
            throw new \LogicException('array_reduce() requires VM context in this compiler build');
        }
        $hasInitial = 3 === $argc;
        $initial = $hasInitial ? $frame->calledArgs[2]->resolveIndirect() : null;
        $fn = VmUserCall::resolveStringCallback($frame->vmContext, $callback->toString());
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
            if (null === $carry && !$hasInitial) {
                $carry = $item;
                continue;
            }
            $carry = VmUserCall::invoke($frame->vmContext, $fn, $carry, $item);
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
        if (!ArrayReduceCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_reduce() callback');
        }
        $initial = 3 === $argc ? $args[2] : null;

        return ArrayBuiltinHelper::buildReduceArray($context, $args[0], $args[1], $initial);
    }
}
