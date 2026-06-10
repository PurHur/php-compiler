<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_wrapper_register() — register userspace stream protocol (ext/standard/streams.c; #3383). */
final class stream_wrapper_register extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_wrapper_register');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_wrapper_register() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $protocol = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_wrapper_register',
            0,
            'protocol'
        );
        $className = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'stream_wrapper_register',
            1,
            'class'
        );
        $frame->returnVar->bool(VmStreamWrapperRegistry::register($protocol, $className));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_wrapper_register() is not implemented for JIT in this compiler build (issue #3383)');
    }
}
