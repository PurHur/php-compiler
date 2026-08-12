<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * debug_backtrace() — stack trace array (issue #1378; options #3626).
 *
 * VM: walks Frame parent chain. JIT: {@see JitDebugBacktrace} (compile-time frames; #1378, #1870, #1056).
 */
final class debug_backtrace extends Internal
{
    public function __construct()
    {
        parent::__construct('debug_backtrace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('debug_backtrace() expects at most 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $options = 0;
        $limit = 0;
        if (isset($frame->calledArgs[0])) {
            $options = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                0,
                'debug_backtrace',
                1,
                'options'
            );
        }
        if (isset($frame->calledArgs[1])) {
            $limit = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'debug_backtrace',
                2,
                'limit'
            );
        }
        $frame->returnVar->copyFrom(VmDebugBacktrace::build($frame, $options, $limit));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('debug_backtrace() expects at most 2 arguments, %d given', $argc)
            );

            return $slot;
        }

        $optionsArg = null;
        if ($argc >= 1) {
            if (
                JITVariable::TYPE_NULL === $args[0]->type
                || ($args[0]->isNullConstant ?? false)
            ) {
                if ($context->callerStrictTypes) {
                    \PHPCompiler\JIT\InternalStrictArg::requireInt(
                        $context,
                        $args[0],
                        'debug_backtrace',
                        'options',
                        1
                    );

                    return JitDebugBacktrace::invoke($context, null);
                }
            }
            $optionsArg = $args[0];
        }

        return JitDebugBacktrace::invoke($context, $optionsArg);
    }
}
