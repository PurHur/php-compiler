<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_access() — path accessibility probe (php-src ext/posix/posix.c; #7376). */
final class posix_access extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_access');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'posix_access() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_access', 0, 'filename');
        $mode = PosixConstants::POSIX_F_OK;
        if ($argc >= 2) {
            $mode = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_access', 1, 'flags');
        }
        $frame->returnVar->bool(VmPosix::access($path, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_access() is not implemented for JIT in this compiler build (issue #7376)');
    }
}
