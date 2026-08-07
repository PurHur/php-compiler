<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_connect() — TCP connect + FTP greeting (php-src ext/ftp/php_ftp.c; #3353, #27393).
 *
 * $hostname — nullable internal string: DEP+coerce on 8.4 forward profile (#21757, ext/ftp/ftp.c).
 *
 * JIT/AOT: {@see JitFtpConnect} → NestedJIT {@see FtpConnectJitHelper} (#27393).
 */
final class ftp_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_connect() expects from 1 to 3 arguments, %d given',
                $argc
            ));
        }
        $hostname = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'ftp_connect',
            0,
            'hostname',
            'string',
            false
        );
        if (null === $frame->returnVar) {
            return;
        }

        $port = 21;
        if ($argc >= 2) {
            $port = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_connect', 2, 'port');
        }
        $timeout = 90;
        if ($argc >= 3) {
            $timeout = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ftp_connect', 3, 'timeout');
        }

        $result = VmFtpCore::connect($hostname, $port, $timeout, $frame->vmContext);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpConnect::invoke($context, ...$args);
    }
}
