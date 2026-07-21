<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_ssl_connect() — implicit TLS FTP connect (php-src ext/ftp/php_ftp.c; #6565).
 *
 * $hostname — nullable internal string: DEP+coerce on 8.4 forward profile (#21757, ext/ftp/ftp.c).
 */
final class ftp_ssl_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_ssl_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_ssl_connect() expects from 1 to 3 arguments, %d given',
                $argc
            ));
        }
        $hostname = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'ftp_ssl_connect',
            0,
            'hostname',
            'string',
            false
        );
        if (null === $frame->returnVar) {
            return;
        }

        $port = 990;
        if ($argc >= 2) {
            $port = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_ssl_connect', 2, 'port');
        }
        $timeout = 90;
        if ($argc >= 3) {
            $timeout = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'ftp_ssl_connect', 3, 'timeout');
        }

        $result = VmFtpCore::sslConnect($hostname, $port, $timeout, $frame->vmContext);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException(\sprintf(
                'ftp_ssl_connect() expects from 1 to 3 arguments, %d given',
                $argc
            ));
        }
        JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'ftp_ssl_connect',
            0,
            'hostname'
        );

        throw new \LogicException('ftp_ssl_connect() is not implemented for JIT in this compiler build (issue #6565)');
    }
}
