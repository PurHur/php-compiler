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
 * debug_print_backtrace() — echo flat stack trace (ext/standard/debug_backtrace.c, #3314).
 *
 * @see VmDebugBacktrace::printFlat()
 */
final class debug_print_backtrace extends Internal
{
    public function __construct()
    {
        parent::__construct('debug_print_backtrace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'debug_print_backtrace() expects at most 2 arguments, '.$argc.' given'
            );
        }
        $options = 0;
        $limit = 0;
        if ($argc >= 1) {
            $options = $frame->calledArgs[0]->resolveIndirect()->toInt();
        }
        if ($argc >= 2) {
            $limit = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        VmDebugBacktrace::printFlat($frame, $options, $limit);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'debug_print_backtrace() expects at most 2 arguments, '.$argc.' given'
            );

            return JitValueBox::pointer($context, $slot);
        }

        return JitDebugPrintBacktrace::invoke(
            $context,
            $argc >= 1 ? $args[0] : null,
            $argc >= 2 ? $args[1] : null
        );
    }
}
