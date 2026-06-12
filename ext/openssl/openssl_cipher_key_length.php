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
 * openssl_cipher_key_length() — cipher key length probe (php-src ext/openssl/openssl.c; #6522).
 */
final class openssl_cipher_key_length extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cipher_key_length');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_cipher_key_length() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $cipher = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'openssl_cipher_key_length',
            0,
            'cipher_algo'
        );
        $length = VmOpenssl::cipher_key_length($cipher);
        if (false === $length) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($length);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_cipher_key_length() is not implemented for JIT in this compiler build (issue #6522)'
        );
    }
}
