<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** posix_eaccess() — eaccess(3) (php-src ext/posix/posix.c; #20509). */
final class posix_eaccess extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_eaccess');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'posix_eaccess() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_eaccess', 0, 'filename');
        if ('' === $filename) {
            throw new \ValueError('posix_eaccess(): Argument #1 ($filename) must not be empty');
        }
        $mode = PosixConstants::POSIX_F_OK;
        if ($argc >= 2) {
            $mode = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_eaccess', 1, 'flags');
        }
        $frame->returnVar->bool(VmPosix::eaccess($filename, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_eaccess() is not implemented for JIT in this compiler build (issue #20509)');
    }
}
