<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pkey_get_details() — key parameter array (php-src ext/openssl/openssl.c; #20240).
 */
final class openssl_pkey_get_details extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_get_details');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_pkey_get_details() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $details = VmOpenssl::pkeyGetDetails($frame->calledArgs[0], $frame);
        if (false === $details) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($details);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_get_details() is not implemented for JIT in this compiler build (issue #20240)'
        );
    }
}
