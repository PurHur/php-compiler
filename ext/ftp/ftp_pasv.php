<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ftp_pasv() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_pasv extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_pasv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_pasv() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_pasv');
        $enable = $frame->calledArgs[1]->resolveIndirect()->toBool();
        $frame->returnVar->bool(VmFtpCore::pasv($connection, $enable));

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpNav::invokePasv($context, ...$args);
    }
}
