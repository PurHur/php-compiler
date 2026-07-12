<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_kill() — send signal to process (php-src ext/posix/posix.c; issue #6680). */
final class posix_kill extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_kill');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('posix_kill() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pid = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_kill', 0, 'process_id');
        $sig = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_kill', 1, 'sig');
        $frame->returnVar->bool(VmPosix::kill($pid, $sig));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_kill() is not implemented for JIT in this compiler build (issue #6680)');
    }
}
