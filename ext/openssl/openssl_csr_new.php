<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_csr_new() — create certificate signing request (php-src ext/openssl/xp.c; #6421).
 */
final class openssl_csr_new extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_new');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_csr_new() expects 2 to 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $options = $argc >= 3 ? $frame->calledArgs[2] : null;
        $csr = VmOpenssl::csrNew(
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $options,
            $frame->vmContext,
            $frame
        );
        if (false === $csr) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($csr->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_csr_new() is not implemented for JIT in this compiler build (issue #6421)'
        );
    }
}
