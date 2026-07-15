<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_context_get_params() — read options + params metadata (ext/standard/streams.c, #3448).
 */
final class stream_context_get_params extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_get_params');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'stream_context_get_params() requires exactly one argument in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmStreamContext::getParamsHashTable($frame->calledArgs[0]));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextGetParams::invoke($context, ...$args);
    }
}
