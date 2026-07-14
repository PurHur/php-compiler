<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_context_set_option() — singular or batch wrapper option write (ext/standard/streams.c, #3448).
 */
final class stream_context_set_option extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc && 4 !== $argc) {
            throw new \LogicException(
                'stream_context_set_option() requires two or four arguments in this compiler build'
            );
        }
        $ok = 2 === $argc
            ? VmStreamContext::setOption($frame->calledArgs[0], $frame->calledArgs[1])
            : VmStreamContext::setOption(
                $frame->calledArgs[0],
                $frame->calledArgs[1],
                $frame->calledArgs[2],
                $frame->calledArgs[3]
            );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextSetOption::invoke($context, ...$args);
    }
}
