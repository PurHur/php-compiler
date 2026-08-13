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
use PHPCompiler\JIT\ArrayFindCallbackPolicy;
use PHPCompiler\JIT\ArrayFindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_any() — true when any element matches a predicate (PHP 8.4; ext/standard/array.c).
 *
 * php-src stub: array_any(array $array, callable $callback): bool — no $strict (#23875).
 */
final class array_any extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayValueCallback::requireExactTwoArgs($frame->calledArgs, 'array_any');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_any');
        $callback = $frame->calledArgs[1];
        VmArrayValueCallback::requireCallback($frame, $callback, 'array_any');
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $result = VmArrayValueCallback::invokePredicate($frame, $callback, $value, $key, 'array_any');
            if (VmArrayValueCallback::isTruthy($result)) {
                $frame->returnVar->bool(true);

                return;
            }
        }
        $frame->returnVar->bool(false);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            $slot = JitValueBox::alloc($context);
            $result = JitValueBox::pointer($context, $slot);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('array_any() expects exactly 2 arguments, %d given', $argc)
            );

            return $result;
        }
        $vacuous = ArrayFindHelper::vacuousAnyAllIfCompileTimeEmpty($context, $args[0], false);
        if (null !== $vacuous) {
            return $vacuous;
        }
        if (ArrayFindCallbackPolicy::isJitNullCallback($args[1])) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                ArrayFindCallbackPolicy::invalidCallbackTypeError('array_any')
            );
            // Catchable throw closed the block — resume with a dummy return (#30624 / peer memory_get_usage).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'array_any_null_cb_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (!ArrayFindCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_any() callback');
        }

        return ArrayFindHelper::buildAnyArray($context, $args[0], $args[1]);
    }
}
