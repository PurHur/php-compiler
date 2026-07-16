<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ftp_nb_continue() — advance a non-blocking FTP transfer (php-src ext/ftp/php_ftp.c; #6675).
 */
final class ftp_nb_continue extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_nb_continue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_nb_continue() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_nb_continue');
        $frame->returnVar->int(VmFtpCore::nbContinue($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_nb_continue() is not implemented for JIT in this compiler build (issue #6675)');
    }
}
