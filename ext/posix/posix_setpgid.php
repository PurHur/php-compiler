<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_setpgid() — set process group (VM VmPosix; JIT/AOT PosixSetpgidJitHelper via PosixSetpgidJit, #31235/#6505). */
final class posix_setpgid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setpgid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('posix_setpgid() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_setpgid', 0, 'process_id');
        $pgid = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_setpgid', 1, 'process_group_id');
        $frame->returnVar->bool(VmPosix::setpgid($pid, $pgid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_setpgid() expects exactly 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPosix::setpgid($context, $args[0], $args[1]);
    }
}
