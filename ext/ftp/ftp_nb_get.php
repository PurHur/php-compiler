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
 * ftp_nb_get() — non-blocking download to a local file (php-src ext/ftp/php_ftp.c; #6675).
 */
final class ftp_nb_get extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_nb_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_nb_get() expects from 3 to 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_nb_get');
        $localFile = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_nb_get', 1, 'local_filename');
        $remoteFile = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_nb_get', 2, 'remote_filename');
        $mode = FtpConstants::FTP_BINARY;
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ftp_nb_get', 4, 'mode');
        }
        $resumePos = 0;
        if ($argc >= 5) {
            $resumePos = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'ftp_nb_get', 5, 'resume_pos');
        }

        $frame->returnVar->int(VmFtpCore::nbGet($connection, $localFile, $remoteFile, $mode, $resumePos));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_nb_get() is not implemented for JIT in this compiler build (issue #6675)');
    }
}
