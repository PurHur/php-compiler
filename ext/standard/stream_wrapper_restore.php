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
        $frame->returnVar->bool(VmStreamWrapperRegistry::restore($protocol, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!JitStreamWrapperRegistry::requireExactArgCount($context, $args, 'stream_wrapper_restore', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        JitStringBuiltinArg::lower($context, $args[0], 'stream_wrapper_restore', 0, 'protocol');

        return JitStreamWrapperRegistry::restore($context, $args[0]);
    }
}
