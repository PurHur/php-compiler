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

/** posix_getsid() — session ID for process (php-src ext/posix/posix.c; #6505). */
final class posix_getsid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_getsid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_getsid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_getsid', 0, 'pid');
        $sid = VmPosix::getsid($pid);
        if (false === $sid) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($sid);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_getsid() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        throw new \Error('posix_getsid() is not implemented for JIT in this compiler build (issue #6505)');
    }
}
