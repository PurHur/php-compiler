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
 * stream_context_set_params() — attach params bag to stream context (ext/standard/streams.c, #6122).
 */
final class stream_context_set_params extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_set_params');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'stream_context_set_params() requires exactly two arguments in this compiler build'
            );
        }
        $ok = VmStreamContext::setParams($frame->calledArgs[0], $frame->calledArgs[1]);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_context_set_params() is not implemented for JIT in this compiler build (issue #6122)'
        );
    }
}
