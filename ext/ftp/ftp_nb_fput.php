<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_nb_fput() — non-blocking upload from an open stream (php-src ext/ftp/php_ftp.c; #20234).
 */
final class ftp_nb_fput extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_nb_fput');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_nb_fput() expects from 3 to 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_nb_fput');
        $remoteFile = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_nb_fput', 1, 'remote_filename');
        $streamHandle = VmStreamArg::requireStreamHandle($frame->calledArgs[2], 'ftp_nb_fput', 3);
        $mode = FtpConstants::FTP_BINARY;
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ftp_nb_fput', 4, 'mode');
        }
        $offset = 0;
        if ($argc >= 5) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'ftp_nb_fput', 5, 'offset');
        }

        $frame->returnVar->int(VmFtpCore::nbFput($connection, $remoteFile, $streamHandle, $mode, $offset));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_nb_fput() is not implemented for JIT in this compiler build (issue #20234)');
    }
}
