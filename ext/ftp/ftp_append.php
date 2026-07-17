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
 * ftp_append() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_append extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_append');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_append() expects from 3 to 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_append');
        $remoteFile = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_append', 1, 'remote_filename');
        $localFile = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_append', 2, 'local_filename');
        $mode = FtpConstants::FTP_BINARY;
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ftp_append', 4, 'mode');
        }

        $frame->returnVar->bool(VmFtpCore::append($connection, $remoteFile, $localFile, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_append() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
