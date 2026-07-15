<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_csr_get_public_key() — public key from CSR (php-src ext/openssl/xp.c; #6421).
 */
final class openssl_csr_get_public_key extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_get_public_key');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_csr_get_public_key() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $key = VmOpenssl::csrGetPublicKey($frame->calledArgs[0], $frame->vmContext, $frame);
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_csr_get_public_key() is not implemented for JIT in this compiler build (issue #6421)'
        );
    }
}
