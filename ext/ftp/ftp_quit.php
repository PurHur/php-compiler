<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ftp_quit() — alias of ftp_close() (php-src PHP_FALIAS; #20233, #31377).
 *
 * JIT/AOT: shares {@see JitFtpClose} / {@see FtpCloseJitHelper} with ftp_close().
 */
final class ftp_quit extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_quit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_quit() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_quit');
        $frame->returnVar->bool(VmFtpCore::close($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitFtpClose::emitArgumentCountError($context, 'ftp_quit', $argc);
        }

        return JitFtpClose::invoke($context, 'ftp_quit', $args[0]);
    }
}
