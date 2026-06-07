<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_setgid() — set real group ID (php-src ext/posix/posix.c; #7376). */
final class posix_setgid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setgid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_setgid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $gid = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_setgid', 0, 'gid');
        $frame->returnVar->bool(VmPosix::setgid($gid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_setgid() is not implemented for JIT in this compiler build (issue #7376)');
    }
}
