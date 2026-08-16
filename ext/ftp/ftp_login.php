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
 * ftp_login() — USER/PASS auth (php-src ext/ftp/php_ftp.c; #3353, #6762, #31378).
 *
 * JIT/AOT: {@see JitFtpLogin} → NestedJIT {@see FtpLoginJitHelper} (#31378).
 */
final class ftp_login extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_login');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_login() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_login');
        $username = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_login', 1, 'username');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_login', 2, 'password');
        $frame->returnVar->bool(VmFtpCore::login($connection, $username, $password));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpLogin::invoke($context, ...$args);
    }
}
