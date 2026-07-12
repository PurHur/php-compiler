<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_error_string() — drain OpenSSL error queue (php-src ext/openssl/openssl.c; issue #6559).
 */
final class openssl_error_string extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_error_string');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'openssl_error_string() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $message = VmOpensslErrorNative::errorString();
        if (false === $message) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($message);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_error_string() is not implemented for JIT in this compiler build (issue #6559)'
        );
    }
}
