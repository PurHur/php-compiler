<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (null === $frame->returnVar) {
            HeaderCallbackQueue::register($frame->calledArgs[0]);

            return;
        }
        $ok = HeaderCallbackQueue::register($frame->calledArgs[0]);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHeaderRegisterCallback::invoke($context, ...$args);
    }
}
