<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * spl_autoload_unregister() — remove autoload callback from VM stack (ext/spl/php_spl.c, #3580).
 */
final class spl_autoload_unregister extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_unregister');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'spl_autoload_unregister() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->bool(VmSplAutoload::unregister($ctx, $frame->calledArgs[0]));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                'spl_autoload_unregister() expects exactly 1 argument in this compiler build'
            );
        }
        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0])) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeErrorUnregister());
        }
        if (!SplAutoloadCallbackPolicy::isJitLowerable($args[0])) {
            throw new \LogicException(SplAutoloadCallbackPolicy::jitRejectionMessage());
        }

        return JitSplAutoload::unregister($context, $args[0]);
    }
}
