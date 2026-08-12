<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** posix_getpgid() — process group ID (php-src ext/posix/posix.c; #6505). */
final class posix_getpgid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_getpgid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_getpgid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = InternalStrictArg::requireInt($frame, 0, 'posix_getpgid', 'process_id')->toInt();
        $pgid = VmPosix::getpgid($pid);
        if (false === $pgid) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($pgid);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_getpgid() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPosix::getpgid($context, $args[0]);
    }
}
