<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\ShutdownCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * register_shutdown_function() — queue callables for script end (basic_functions.c, issue #3120).
 */
final class register_shutdown_function extends Internal
{
    public function __construct()
    {
        parent::__construct('register_shutdown_function');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('register_shutdown_function() requires at least one argument');
        }
        $callable = $frame->calledArgs[0];
        if (EnumCaseSupport::isEnumCaseVariable($callable)) {
            throw new \TypeError(ShutdownCallbackPolicy::invalidCallbackTypeError());
        }
        $resolved = $callable->resolveIndirect();
        $extra = [];
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $extra[] = $copy;
        }
        if (VmClosureCall::isClosure($resolved)) {
            ShutdownQueue::registerClosure(VmClosureCall::resolve($resolved), ...$extra);

            return;
        }
        if (!VmCallable::isCallable($frame->vmContext, $callable)) {
            throw new \TypeError(ShutdownCallbackPolicy::invalidCallbackTypeError());
        }
        $callableCopy = new Variable();
        $callableCopy->copyFrom($resolved);
        ShutdownQueue::register($callableCopy, ...$extra);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if ([] !== $args && null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0])) {
            throw new \TypeError(ShutdownCallbackPolicy::invalidCallbackTypeError());
        }

        return JitRegisterShutdown::invoke($context, ...$args);
    }
}
