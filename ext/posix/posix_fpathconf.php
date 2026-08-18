<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * posix_fpathconf() — fpathconf(3) (php-src ext/posix/posix.c; #20509).
 *
 * Reflection stub: untyped $file_descriptor, int $name → int|false (#27918; posix.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class posix_fpathconf extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_fpathconf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'posix_fpathconf() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fd = VmPosix::resolveFileDescriptorArg($frame->calledArgs[0], 'posix_fpathconf', 0);
        if (null === $fd) {
            $frame->returnVar->bool(false);

            return;
        }
        $name = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_fpathconf', 1, 'name');
        $ret = VmPosix::fpathconf($fd, $name);
        if (false === $ret) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($ret);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_fpathconf() is not implemented for JIT in this compiler build (issue #20509)');
    }
}
