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
 * ftp_put() — php-src ext/ftp/php_ftp.c; issue #20033 / #31429.
 *
 * JIT/AOT: {@see JitFtpTransfer} → NestedJIT {@see FtpTransferJitHelper}.
 */
final class ftp_put extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_put');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_put() expects from 3 to 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_put');
        $remoteFile = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_put', 1, 'remote_filename');
        $localFile = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_put', 2, 'local_filename');
        $mode = FtpConstants::FTP_BINARY;
        if ($argc >= 4) {
            $mode = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'ftp_put', 4, 'mode');
        }
        $offset = 0;
        if ($argc >= 5) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'ftp_put', 5, 'offset');
        }

        $frame->returnVar->bool(VmFtpCore::put($connection, $remoteFile, $localFile, $mode, $offset));

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpTransfer::invokePut($context, ...$args);
    }
}
