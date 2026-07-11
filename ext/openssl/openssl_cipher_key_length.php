<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_cipher_key_length() — cipher key length probe (php-src ext/openssl/openssl.c; #6522).
 *
 * VM: VmOpenssl + OpensslCipherRegistry. JIT/AOT: compile-time literal baking via JitOpensslCipherKeyLength.
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
        $length = VmOpenssl::cipher_key_length($cipher, $frame);
        if (false === $length) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($length);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'openssl_cipher_key_length() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitOpensslCipherKeyLength::invoke($context, $args[0]);
    }
}
