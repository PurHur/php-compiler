<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_context_get_default() — process-wide default stream context (ext/standard/streams.c, #6367).
 *
 * JIT: {@see JitStreamContextGetDefault}.
 */
final class stream_context_get_default extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_get_default');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException(
                'stream_context_get_default() accepts at most one argument in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $optionsVar = $argc >= 1 ? $frame->calledArgs[0] : null;
        $frame->returnVar->copyFrom(VmStreamContext::getDefault($optionsVar));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextGetDefault::invoke($context, ...$args);
    }
}
