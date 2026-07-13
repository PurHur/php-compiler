<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_dh_compute_key() — DH shared secret from raw peer public bytes (php-src ext/openssl/openssl_backend_v3.c; #6596).
 */
final class openssl_dh_compute_key extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_dh_compute_key');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_dh_compute_key() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $pubKey = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'openssl_dh_compute_key',
            0,
            'public_key'
        );
        $privatePem = VmOpenssl::coercePkeyPem(
            $frame->calledArgs[1],
            'openssl_dh_compute_key',
            1,
            'private_key'
        );

        $shared = VmOpenssl::dhComputeKey($pubKey, $privatePem, $frame);
        if (false === $shared) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($shared);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_dh_compute_key() is not implemented for JIT in this compiler build (issue #6596)'
        );
    }
}
