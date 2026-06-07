<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_mknod() — create special file (php-src ext/posix/posix.c; #7376). */
final class posix_mknod extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_mknod');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'posix_mknod() expects at least 2 arguments and at most 4, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_mknod', 0, 'filename');
        $mode = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_mknod', 1, 'mode');
        $major = 0;
        $minor = 0;
        if ($argc >= 3) {
            $major = VmPosix::coerceIntArg($frame->calledArgs[2], 'posix_mknod', 2, 'major');
        }
        if ($argc >= 4) {
            $minor = VmPosix::coerceIntArg($frame->calledArgs[3], 'posix_mknod', 3, 'minor');
        }
        $frame->returnVar->bool(VmPosix::mknod($path, $mode, $major, $minor));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_mknod() is not implemented for JIT in this compiler build (issue #7376)');
    }
}
