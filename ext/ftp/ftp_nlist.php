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
 * ftp_nlist() — php-src ext/ftp/php_ftp.c; issue #20033.
 */
final class ftp_nlist extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_nlist');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_nlist() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_nlist');
        $directory = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ftp_nlist', 1, 'directory');
        $result = VmFtpCore::nlist($connection, $directory);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);

    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFtpQuery::invokeNlist($context, ...$args);
    }
}
