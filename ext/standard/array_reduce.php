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
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Builtin\ArrayReduceRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_reduce() — string, closure, invokable, and object-array callables (ext/standard/array.c; #25763).
 *
 * JIT/AOT: compile-time string user-function names in this compile unit (#1213); array callables VM-only.
 *
 * Excess/missing argc → ArgumentCountError (#28473).
 */
final class array_reduce extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.stub.php — ArgumentCountError (#28473).
        $this->requireArgCountRange($frame, 'array_reduce', 2, 3);
        $argc = \count($frame->calledArgs);
        $callback = $frame->calledArgs[1]->resolveIndirect();
        $array = VmArray::requireArrayParam($frame->calledArgs[0], 'array_reduce', 1, 'array');
        if (null === $frame->vmContext) {
            throw new \LogicException('array_reduce() requires VM context in this compiler build');
        }
        $hasInitial = 3 === $argc;
        $initial = $hasInitial ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (null === $frame->returnVar) {
            return;
        }
        // Closures / invokables / object-array callables — scoped like array_map (#25763).
        if (VmClosureCall::isClosure($callback)
            || Variable::TYPE_OBJECT === $callback->type
            || Variable::TYPE_ARRAY === $callback->type
        ) {
            if (!VmClosureCall::isClosure($callback)) {
                if (!VmCallable::isCallable($frame->vmContext, $callback, false, null, $frame)) {
                    VmCallable::throwIfInaccessibleMethodCallback(
                        $frame->vmContext,
                        $callback,
                        'array_reduce',
                        2,
                        $frame
                    );
                    throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
                }
            }
            self::reduceWithVmCallable($frame, $array, $hasInitial, $initial, $callback);

            return;
        }
        [$closure, $callbackFn] = VmReduceCallback::resolve($frame, $callback);
        if ($callbackFn instanceof Internal && null === $closure) {
            $nullInitial = new Variable();
            $nullInitial->null();
            $initialOrNull = $hasInitial ? $initial : $nullInitial;
            $frame->returnVar->copyFrom(
                ArrayReduceJitHelper::reduceWithBuiltin($array, $callbackFn->name, $initialOrNull)
            );

            return;
        }
        self::reduceVm($frame, $array, $hasInitial, $initial, $closure, $callbackFn, $frame->vmContext);
    }

    private static function reduceWithVmCallable(
        Frame $frame,
        HashTable $array,
        bool $hasInitial,
        ?Variable $initial,
        Variable $callback
    ): void {
        $ctx = $frame->vmContext;
        $carry = null;
        if ($hasInitial) {
            $carry = new Variable();
            $carry->copyFrom($initial);
        }
        $empty = true;
        foreach ($array->iterateKeyed(true) as [, $value]) {
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
            $carry = VmCallable::invokeAsWithScope(
                'array_reduce',
                $ctx,
                $frame,
                $callback,
                $carryArg,
                $item
            );
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

    /**
     * @param ClosureState|Internal|Func\PHP|null $callbackFn
     */
    private static function reduceVm(
        Frame $frame,
        HashTable $array,
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
        foreach ($array->iterateKeyed(true) as [, $value]) {
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
        // Catchable ArgumentCountError (AOT/JIT) — #28473.
        if (!$this->requireArgCountRangeJit($context, $args, 'array_reduce', 2, 3)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $argc = \count($args);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_reduce', 1, 'array');
        if ($args[1]->isNullConstant) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (!ArrayReduceCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        $initial = 3 === $argc ? $args[2] : null;
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_reduce() callback');
        }

        return ArrayReduceRuntime::reduce($context, $args[0], $args[1], $initial);
    }
}
