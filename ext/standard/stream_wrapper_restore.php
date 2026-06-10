<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_wrapper_restore() — restore built-in/custom stream protocol (ext/standard/streams.c; #6818). */
final class stream_wrapper_restore extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_wrapper_restore');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_wrapper_restore() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $protocol = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_wrapper_restore',
            0,
            'protocol'
        );
        $frame->returnVar->bool(VmStreamWrapperRegistry::restore($protocol));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_wrapper_restore() is not implemented for JIT in this compiler build (issue #6818)');
    }
}
