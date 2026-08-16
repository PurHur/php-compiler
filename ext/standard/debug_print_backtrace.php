<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
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
        // php-src basic_functions.stub.php: int $options = …, int $limit = 0 (#31384).
        $options = 0;
        $limit = 0;
        if ($argc >= 1) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce.
            $options = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                0,
                'debug_print_backtrace',
                1,
                'options'
            );
        }
        if ($argc >= 2) {
            $limit = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'debug_print_backtrace',
                2,
                'limit'
            );
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

        $optionsArg = null;
        if ($argc >= 1) {
            // Soft-null outside strict_types; strict → TypeError (#31384).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller(
                    $context,
                    $args[0],
                    'debug_print_backtrace',
                    1,
                    'options'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'debug_print_backtrace_null_options_te_cont');

                return JitValueBox::pointer($context, JitValueBox::alloc($context));
            }
            $optionsI64 = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[0],
                'debug_print_backtrace',
                1,
                'options'
            );
            $optionsArg = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $optionsI64
            );
        }
        if ($argc >= 2) {
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller(
                    $context,
                    $args[1],
                    'debug_print_backtrace',
                    2,
                    'limit'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'debug_print_backtrace_null_limit_te_cont');

                return JitValueBox::pointer($context, JitValueBox::alloc($context));
            }
            // Side-effect: soft-null DEP+coerce / strict TypeError (#31384); JIT ignore-args path
            // does not consume $limit yet (same as prior invoke(..., $limitArg) unset).
            JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'debug_print_backtrace',
                2,
                'limit'
            );
        }

        return JitDebugPrintBacktrace::invoke($context, $optionsArg, null);
    }
}
