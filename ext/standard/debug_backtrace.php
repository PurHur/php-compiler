<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * debug_backtrace() — minimal stack trace array (issue #1378).
 *
 * VM: walks Frame parent chain. JIT lowering in {@see JitDebugBacktrace} (deferred in
 * {@see \PHPCompiler\JIT\SelfHostBuiltinPolicy::VM_ONLY_DEFERRED} until MCJIT frame arrays are stable).
 */
final class debug_backtrace extends Internal
{
    public function __construct()
    {
        parent::__construct('debug_backtrace');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException(
                'debug_backtrace() options are not supported in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDebugBacktrace::build($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException(
            'debug_backtrace() is not implemented for JIT in this compiler build (#1378)'
        );
    }
}
