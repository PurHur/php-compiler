<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * ftp_get_option() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_get_option extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_get_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_get_option() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_get_option');
        $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_get_option', 2, 'option');
        $result = VmFtpCore::getOption($connection, $option);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_bool($result)) {
            $frame->returnVar->bool($result);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_get_option() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
