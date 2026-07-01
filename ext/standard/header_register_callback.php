<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HeaderCallbackPolicy;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HeaderCallbackQueue;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * header_register_callback() — run callables before response headers are sent (head.c, #3759).
 */
final class header_register_callback extends Internal
{
    public function __construct()
    {
        parent::__construct('header_register_callback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('header_register_callback() requires exactly one argument');
        }
        $callable = $frame->calledArgs[0];
        if (VmClosureCall::isClosure($callable->resolveIndirect())) {
            // Valid — register below.
        } elseif (!VmCallable::isCallable($frame->vmContext, $callable)) {
            throw new \TypeError(HeaderCallbackPolicy::invalidCallbackTypeError());
        }
        if (null === $frame->returnVar) {
            HeaderCallbackQueue::register($callable);

            return;
        }
        $ok = HeaderCallbackQueue::register($callable);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if ([] !== $args && (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)) {
            throw new \TypeError(HeaderCallbackPolicy::invalidCallbackTypeError());
        }
        if ([] !== $args && null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $args[0])) {
            throw new \TypeError(HeaderCallbackPolicy::invalidCallbackTypeError());
        }

        return JitHeaderRegisterCallback::invoke($context, ...$args);
    }
}
