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
 * ftp_mkdir() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_mkdir extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_mkdir() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_mkdir');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_mkdir', 1, 'directory');
        $result = VmFtpCore::mkdir($connection, $directory);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpMutate::invokeMkdir($context, ...$args);
    }
}
