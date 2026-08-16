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
 * ftp_rmdir() — remove a remote directory (php-src ext/ftp/php_ftp.c; #20232).
 */
final class ftp_rmdir extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_rmdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_rmdir() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_rmdir');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_rmdir', 1, 'directory');
        $frame->returnVar->bool(VmFtpCore::rmdir($connection, $directory));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpMutate::invokeRmdir($context, ...$args);
    }
}
