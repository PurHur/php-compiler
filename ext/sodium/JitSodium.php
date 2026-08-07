<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\JIT\Builtin\StringSodium;
use PHPCompiler\JIT\Builtin\StringSodiumAead;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for sodium builtins via __compiler_sodium_* runtime (#13078). */
final class JitSodium
{
    public static function invokeAeadXchachaIetfEncrypt(
        Context $context,
        Value $message,
        Value $additionalData,
        Value $nonce,
        Value $key
    ): Value {
        return StringSodiumAead::invokeEncrypt($context, $message, $additionalData, $nonce, $key);
    }

    public static function invokeAeadXchachaIetfDecrypt(
        Context $context,
        Value $ciphertext,
        Value $additionalData,
        Value $nonce,
        Value $key
    ): Value {
        return StringSodiumAead::invokeDecrypt($context, $ciphertext, $additionalData, $nonce, $key);
    }

    public static function invoke(
        Context $context,
        string $name,
        Value $message,
        Value $nonce,
        Value $key
    ): Value {
        StringSodium::ensureLinked($context);
        $abi = 'sodium_crypto_secretbox_open' === $name
            ? '__compiler_sodium_secretbox_open'
            : '__compiler_sodium_secretbox';

        return $context->builder->call(
            $context->lookupFunction($abi),
            $message,
            $nonce,
            $key
        );
    }

    public static function invokeAuth(Context $context, Value $message, Value $key): Value
    {
        StringSodium::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_sodium_auth'),
            $message,
            $key
        );
    }

    public static function invokeAuthVerify(
        Context $context,
        Value $mac,
        Value $message,
        Value $key
    ): Value {
        StringSodium::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_sodium_auth_verify'),
            $mac,
            $message,
            $key
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $result,
            $i32->constInt(0, false)
        );
    }

    public static function invokeMemcmp(Context $context, Value $string1, Value $string2): Value
    {
        StringSodium::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_sodium_memcmp'),
            $string1,
            $string2
        );
    }

    public static function invokeCompare(Context $context, Value $string1, Value $string2): Value
    {
        StringSodium::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_sodium_compare'),
            $string1,
            $string2
        );
    }

    public static function invokePad(Context $context, string $name, Value $string, Value $blockSize): Value
    {
        return StringSodium::invokePadHelper($context, $name, $string, $blockSize);
    }

    public static function invokeStreamXor(
        Context $context,
        string $name,
        Value $message,
        Value $nonce,
        Value $key
    ): Value {
        StringSodium::ensureLinked($context);
        $abi = 'sodium_crypto_stream_xchacha20_xor' === $name
            ? '__compiler_sodium_stream_xchacha20_xor'
            : '__compiler_sodium_stream_xor';

        return $context->builder->call(
            $context->lookupFunction($abi),
            $message,
            $nonce,
            $key
        );
    }
}
