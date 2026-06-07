<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_setuid() — set real user ID (php-src ext/posix/posix.c; #7376). */
final class posix_setuid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setuid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_setuid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uid = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_setuid', 0, 'uid');
        $frame->returnVar->bool(VmPosix::setuid($uid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_setuid() is not implemented for JIT in this compiler build (issue #7376)');
    }
}
