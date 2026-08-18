<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * posix_sysconf() — sysconf(3) (php-src ext/posix/posix.c; #20509).
 *
 * Reflection stub: int $conf_id → int (#27918; posix.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class posix_sysconf extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_sysconf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'posix_sysconf() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $confId = VmPosix::coerceIntArg($frame->calledArgs[0], 'posix_sysconf', 0, 'conf_id');
        $frame->returnVar->int(VmPosix::sysconf($confId));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('posix_sysconf() is not implemented for JIT in this compiler build (issue #20509)');
    }
}
