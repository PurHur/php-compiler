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
 * ftp_mlsd() — RFC 3659 machine listing (php-src ext/ftp/php_ftp.c; #6762).
 */
final class ftp_mlsd extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_mlsd');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_mlsd() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_mlsd');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_mlsd', 1, 'directory');
        $result = VmFtpCore::mlsd($connection, $directory);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpList::invokeMlsd($context, ...$args);
    }
}
