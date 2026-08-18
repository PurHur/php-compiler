<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pbkdf2() (#32410 leftover of #6488).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pbkdf2) / PKCS5_PBKDF2_HMAC
 *
 * Compile-time literal bake (peer {@see JitOpensslCipherIvLength}): {@see VmHashNative::hashPbkdf2}
 * in the compiler process. {@see \PHPCompiler\ext\standard\JitHash::hashPbkdf2} SIGSEGVs under AOT
 * on this tree (hash_pbkdf2 itself). key_length <= 0 is catchable ValueError.
 */
final class JitOpensslPbkdf2
{
    private const KEY_LENGTH_ERROR = 'openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $password = JitStringArg::compileTimeLiteral($args[0]);
        $salt = JitStringArg::compileTimeLiteral($args[1]);
        $keyLength = $args[2]->compileTimeLong;
        $iterations = $args[3]->compileTimeLong;
        $algo = 'sha1';
        if (isset($args[4])) {
            $algoLit = JitStringArg::compileTimeLiteral($args[4]);
            if (null === $algoLit) {
                throw new \LogicException(
                    'openssl_pbkdf2() digest_algo must be a compile-time string literal '
                    .'for JIT/AOT in this compiler build (issue #32410)'
                );
            }
            $algo = $algoLit;
        }
        if (null === $password || null === $salt || null === $keyLength || null === $iterations) {
            throw new \LogicException(
                'openssl_pbkdf2() requires compile-time password/salt/key_length/iterations '
                .'for JIT/AOT in this compiler build (issue #32410)'
            );
        }

        if ($keyLength <= 0) {
            $err = BasicBlockHelper::append($context, 'ossl_pbkdf2_keylen_err');
            $after = BasicBlockHelper::append($context, 'ossl_pbkdf2_keylen_after');
            $context->builder->branch($err);
            $context->builder->positionAtEnd($err);
            ExceptionBridge::emitValueErrorAndAbort($context, self::KEY_LENGTH_ERROR);
            $context->builder->positionAtEnd($after);

            return self::boxedFalse($context);
        }

        if ($iterations <= 0 || !OpensslCipherRegistry::digestImplemented($algo)) {
            return self::boxedFalse($context);
        }

        $derived = VmHashNative::hashPbkdf2(
            strtolower($algo),
            $password,
            $salt,
            $iterations,
            $keyLength,
            true
        );
        if ('' === $derived) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($derived))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
