<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_wrapper_unregister() — remove userspace stream protocol (ext/standard/streams.c; #3383). */
final class stream_wrapper_unregister extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_wrapper_unregister');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_wrapper_unregister() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $protocol = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_wrapper_unregister',
            0,
            'protocol'
        );
        $frame->returnVar->bool(VmStreamWrapperRegistry::unregister($protocol));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_wrapper_unregister() is not implemented for JIT in this compiler build (issue #3383)');
    }
}
