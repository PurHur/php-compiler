<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
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
        VmStreamWrapperRegistry::requireValidWrapperClass($frame, $className);
        $frame->returnVar->bool(VmStreamWrapperRegistry::register($protocol, $className));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!JitStreamWrapperRegistry::requireExactArgCount($context, $args, 'stream_wrapper_register', 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        JitStringBuiltinArg::lower($context, $args[0], 'stream_wrapper_register', 0, 'protocol');
        JitStringBuiltinArg::lower($context, $args[1], 'stream_wrapper_register', 1, 'class');

        return JitStreamWrapperRegistry::register($context, $args[0], $args[1]);
    }
}
