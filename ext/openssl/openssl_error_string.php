<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_error_string() — drain OpenSSL error queue (php-src ext/openssl/openssl.c; VM #6559, JIT/AOT #32336).
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
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_error_string() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        return JitOpensslError::invoke($context);
    }
}
