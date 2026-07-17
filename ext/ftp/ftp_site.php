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
 * ftp_site() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_site extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_site');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_site() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_site');
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_site', 1, 'command');
        $frame->returnVar->bool(VmFtpCore::site($connection, $command));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_site() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
