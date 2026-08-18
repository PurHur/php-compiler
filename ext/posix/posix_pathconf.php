<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * posix_pathconf() — pathconf(3) (php-src ext/posix/posix.c; #20509).
 *
 * Reflection stub: string $path, int $name → int|false (#27918; posix.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class posix_pathconf extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_pathconf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'posix_pathconf() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_pathconf', 0, 'path');
        if ('' === $path) {
            throw new \ValueError('posix_pathconf(): Argument #1 ($path) must not be empty');
        }
        $name = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_pathconf', 1, 'name');
        $ret = VmPosix::pathconf($path, $name);
        if (false === $ret) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($ret);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_pathconf() is not implemented for JIT in this compiler build (issue #20509)');
    }
}
