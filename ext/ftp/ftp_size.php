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
 * ftp_size() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_size extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_size');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_size() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_size');
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_size', 1, 'filename');
        $frame->returnVar->int(VmFtpCore::size($connection, $filename));

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpQuery::invokeSize($context, ...$args);
    }
}
