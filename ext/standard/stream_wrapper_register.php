<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
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
        // php-src main/streams/userspace.c — optional $flags (default 0); #31069.
        $this->requireArgCountRange($frame, 'stream_wrapper_register', 2, 3);
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
        if (3 === \count($frame->calledArgs)) {
            VmMath::parseIntBuiltinArgForFrame($frame, 2, 'stream_wrapper_register', 3, 'flags');
        }
        VmStreamWrapperRegistry::requireValidWrapperClass($frame, $className);
        $frame->returnVar->bool(VmStreamWrapperRegistry::register($protocol, $className));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'stream_wrapper_register', 2, 3)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        JitStringBuiltinArg::lower($context, $args[0], 'stream_wrapper_register', 0, 'protocol');
        JitStringBuiltinArg::lower($context, $args[1], 'stream_wrapper_register', 1, 'class');
        if (3 === \count($args)) {
            JitLongArg::lower($context, $args[2], 'stream_wrapper_register', 3, 'flags');
        }

        return JitStreamWrapperRegistry::register($context, $args[0], $args[1]);
    }
}
