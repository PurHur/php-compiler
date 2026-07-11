<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamEnableCrypto;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_socket_enable_crypto() via __compiler_stream_enable_crypto (#4610). */
final class JitStreamEnableCrypto
{
    /** @return Value */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $enableLong,
        Value $hasCryptoMethodLong,
        Value $cryptoMethodLong
    ): Value {
        StreamEnableCrypto::ensureLinked($context);

        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_enable_crypto'),
            $handleLong,
            $enableLong,
            $hasCryptoMethodLong,
            $cryptoMethodLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
