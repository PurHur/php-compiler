<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pbkdf2() — PKCS#5 v2 PBKDF2 (php-src ext/openssl/openssl.c / kdf.c; #6488, JIT/AOT #32410).
 *
 * VM: {@see VmOpenssl::pbkdf2}. JIT/AOT: {@see JitOpensslPbkdf2} compile-time bake via {@see \PHPCompiler\ext\standard\VmHashNative::hashPbkdf2}.
 */
final class openssl_pbkdf2 extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pbkdf2');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_pbkdf2() expects 4 or 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pbkdf2', 0, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_pbkdf2', 1, 'salt');
        $keyLength = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'openssl_pbkdf2', 3, 'key_length');
        $iterations = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_pbkdf2', 4, 'iterations');
        $digestAlgo = 'sha1';
        if (5 === $argc) {
            $digestAlgo = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[4],
                'openssl_pbkdf2',
                4,
                'digest_algo'
            );
        }

        $derived = VmOpenssl::pbkdf2($password, $salt, $keyLength, $iterations, $digestAlgo, $frame);
        if (false === $derived) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($derived);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_pbkdf2() expects 4 or 5 arguments, '.$argc.' given'
            );
        }

        return JitOpensslPbkdf2::invoke($context, ...$args);
    }
}
