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
 * ftp_raw() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_raw extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_raw');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_raw() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_raw');
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_raw', 1, 'command');
        $result = VmFtpCore::raw($connection, $command);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_raw() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
