<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM bake for mhash compat APIs (#32930 leftover of #14975).
 *
 * php-src: ext/hash/hash.c — PHP_FUNCTION(mhash) / mhash_count / mhash_get_hash_name /
 * mhash_get_block_size / mhash_keygen_s2k
 *
 * Thin-standalone AOT has no PHP FFI; bake results in the compiler process via
 * {@see VmMhash} / {@see MhashRegistry} (same shape as {@see \PHPCompiler\ext\openssl\JitOpensslX509}).
 * Algo IDs and string payloads must be compile-time literals.
 */
final class JitMhash
{
    public static function count(Context $context): Value
    {
        return self::boxedLong($context, MhashRegistry::count());
    }

    public static function getHashName(Context $context, JITVariable $algorithm): Value
    {
        $algo = self::compileTimeInt($algorithm);
        if (null === $algo) {
            throw new \LogicException(
                'mhash_get_hash_name() algo must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $name = VmMhash::getHashName($algo);
        if (false === $name) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $name);
    }

    public static function getBlockSize(Context $context, JITVariable $algorithm): Value
    {
        $algo = self::compileTimeInt($algorithm);
        if (null === $algo) {
            throw new \LogicException(
                'mhash_get_block_size() algo must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $size = VmMhash::getBlockSize($algo);
        if (false === $size) {
            return self::boxedFalse($context);
        }

        return self::boxedLong($context, $size);
    }

    public static function mhash(Context $context, JITVariable $algorithm, JITVariable $data): Value
    {
        $algo = self::compileTimeInt($algorithm);
        if (null === $algo) {
            throw new \LogicException(
                'mhash() algo must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $payload = JitStringArg::compileTimeLiteral($data);
        if (null === $payload) {
            throw new \LogicException(
                'mhash() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $digest = VmMhash::mhash($algo, $payload);
        if (false === $digest) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $digest);
    }

    public static function keygenS2k(
        Context $context,
        JITVariable $algorithm,
        JITVariable $password,
        JITVariable $salt,
        JITVariable $bytes
    ): Value {
        $algo = self::compileTimeInt($algorithm);
        if (null === $algo) {
            throw new \LogicException(
                'mhash_keygen_s2k() algo must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $pass = JitStringArg::compileTimeLiteral($password);
        if (null === $pass) {
            throw new \LogicException(
                'mhash_keygen_s2k() password must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $saltLit = JitStringArg::compileTimeLiteral($salt);
        if (null === $saltLit) {
            throw new \LogicException(
                'mhash_keygen_s2k() salt must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        $len = self::compileTimeInt($bytes);
        if (null === $len) {
            throw new \LogicException(
                'mhash_keygen_s2k() bytes must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32930)'
            );
        }
        // php-src ValueError for bytes <= 0 — surface at compile time when baking.
        if ($len <= 0) {
            throw new \ValueError('mhash_keygen_s2k(): Argument #4 ($bytes) must be greater than 0');
        }
        $key = VmMhash::keygenS2k($algo, $pass, $saltLit, $len);
        if (false === $key) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $key);
    }

    private static function compileTimeInt(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            return (int) $lit;
        }

        return null;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($value));
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }

    private static function boxedLong(Context $context, int $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $context->constantFromInteger($value));

        return JitValueBox::pointer($context, $slot);
    }
}
