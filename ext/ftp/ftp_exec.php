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
 * ftp_exec() — SITE EXEC command (php-src ext/ftp/php_ftp.c; #20233).
 */
final class ftp_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_exec');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_exec() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_exec');
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_exec', 1, 'command');
        $frame->returnVar->bool(VmFtpCore::exec($connection, $command));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_exec() is not implemented for JIT in this compiler build (issue #20233)');
    }
}
