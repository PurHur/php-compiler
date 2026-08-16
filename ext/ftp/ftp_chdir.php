<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_chdir() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_chdir extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_chdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_chdir() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_chdir');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_chdir', 1, 'directory');
        $frame->returnVar->bool(VmFtpCore::chdir($connection, $directory));

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpNav::invokeChdir($context, ...$args);
    }
}
