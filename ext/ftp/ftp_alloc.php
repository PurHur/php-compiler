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
 * ftp_alloc() — php-src ext/ftp/php_ftp.c; issue #20060.
 */
final class ftp_alloc extends Internal
{
    public function __construct()
    {
        parent::__construct('ftp_alloc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ftp_alloc() expects from 2 to 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $connection = VmFtpArg::requireConnectionObject($frame->calledArgs[0], 'ftp_alloc');
        $size = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ftp_alloc', 2, 'size');
        $response = null;
        $ok = VmFtpCore::alloc($connection, $size, $response);
        if ($argc >= 3) {
            $out = $frame->calledArgs[2]->byRefTarget();
            if (null === $response) {
                $out->null();
            } else {
                $out->string($response);
            }
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ftp_alloc() is not implemented for JIT in this compiler build (issue #20060)');
    }
}
