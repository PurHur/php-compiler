<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_setrlimit() — set resource limits (php-src ext/posix/posix.c; #7173). */
final class posix_setrlimit extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setrlimit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'posix_setrlimit() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $resource = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_setrlimit', 0, 'resource');
        $soft = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_setrlimit', 1, 'soft_limit');
        $hard = VmPosix::coerceIntArg($frame->calledArgs[2], 'posix_setrlimit', 2, 'hard_limit');
        $frame->returnVar->bool(VmPosix::setrlimit($resource, $soft, $hard));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_setrlimit() is not implemented for JIT in this compiler build (issue #7173)');
    }
}
