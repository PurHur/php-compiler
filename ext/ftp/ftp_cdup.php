<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ftp_cdup() — change to parent directory (php-src ext/ftp/php_ftp.c; #20231).
 */
final class ftp_cdup extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_cdup');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_cdup() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_cdup');
        $frame->returnVar->bool(VmFtpCore::cdup($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpNav::invokeCdup($context, ...$args);
    }
}
