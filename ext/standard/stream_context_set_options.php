<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_context_set_options() — batch merge stream wrapper options (PHP 8.4; ext/standard/streams.c).
 */
final class stream_context_set_options extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_set_options');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'stream_context_set_options() requires exactly two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmStreamContext::setOptions($frame->calledArgs[0], $frame->calledArgs[1]);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextSetOptions::invoke($context, ...$args);
    }
}
