<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * ftp_chmod() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_chmod extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_chmod');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_chmod() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_chmod');
        $permissions = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_chmod', 2, 'permissions');
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ftp_chmod', 2, 'filename');
        $result = VmFtpCore::chmod($connection, $permissions, $filename);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_chmod() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
