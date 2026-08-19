<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_x509_check_private_key() — match cert + private key
 * (php-src ext/openssl/openssl.c; #20285 VM, JIT/AOT #32527).
 */
final class openssl_x509_check_private_key extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_check_private_key');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_x509_check_private_key() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $frame->returnVar->copyFrom(
            VmOpensslObjects::checkPrivateKey(
                $frame->vmContext,
                $frame->calledArgs[0],
                $frame->calledArgs[1],
                $frame
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_x509_check_private_key() expects exactly 2 arguments, '.$argc.' given'
            );
        }

        return JitOpensslX509::checkPrivateKey($context, $args[0], $args[1]);
    }
}
