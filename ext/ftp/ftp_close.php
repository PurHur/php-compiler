<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ftp_close() — tear down FTP\Connection (php-src ext/ftp/php_ftp.c; #3353, #31377).
 *
 * JIT/AOT: {@see JitFtpClose} → NestedJIT {@see FtpCloseJitHelper} (#31377).
 */
final class ftp_close extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_close');
        $frame->returnVar->bool(VmFtpCore::close($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitFtpClose::emitArgumentCountError($context, 'ftp_close', $argc);
        }

        return JitFtpClose::invoke($context, 'ftp_close', $args[0]);
    }
}
