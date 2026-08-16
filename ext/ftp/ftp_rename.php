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
 * ftp_rename() — rename a remote path (php-src ext/ftp/php_ftp.c; #20232).
 */
final class ftp_rename extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_rename');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_rename() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_rename');
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_rename', 1, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_rename', 2, 'to');
        $frame->returnVar->bool(VmFtpCore::rename($connection, $from, $to));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpMutate::invokeRename($context, ...$args);
    }
}
