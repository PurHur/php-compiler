<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\JIT\Builtin\StringSodium;
use PHPCompiler\JIT\Builtin\StringSodiumAead;
use PHPCompiler\JIT\Builtin\StringSodiumGenerichash;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for sodium builtins via __compiler_sodium_* runtime (#13078). */
final class JitSodium
{
    /** #27292 — thin libsodium crypto_generichash (peer AEAD #27318). */
    public static function invokeGenerichash(
        Context $context,
        Value $message,
        Value $key,
        Value $length
    ): Value {
        return StringSodiumGenerichash::invoke($context, $message, $key, $length);
    }

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

    /** sodium_hex2bin NestedJIT — decode after optional ignore strip (#35357). */
    public static function invokeHex2bin(Context $context, Value $string, Value $ignore): Value
    {
        return StringSodium::invokeHex2binHelper($context, $string, $ignore);
    }

    /** Peel one ignore character from hex input (#35357 NestedJIT two-string workaround). */
    public static function invokeStripChar(Context $context, Value $string, Value $char): Value
    {
        return StringSodium::invokeStripCharHelper($context, $string, $char);
    }

    public static function invokeStripByte(Context $context, Value $string, Value $byte): Value
    {
        return StringSodium::invokeStripByteHelper($context, $string, $byte);
    }

    public static function invokeIgnoreByte(Context $context, Value $ignore): Value
    {
        return StringSodium::invokeIgnoreByteHelper($context, $ignore);
    }

    public static function invokeIgnoreRest(Context $context, Value $ignore): Value
    {
        return StringSodium::invokeIgnoreRestHelper($context, $ignore);
    }

    public static function invokeDecode(Context $context, Value $string): Value
    {
        return StringSodium::invokeDecodeHelper($context, $string);
    }

    /** #35378 — sodium_bin2base64 NestedJIT. */
    public static function invokeBin2base64(Context $context, Value $string, Value $id): Value
    {
        return StringSodium::invokeBin2base64Helper($context, $string, $id);
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
