<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\JIT\Builtin\StringSodium;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for sodium builtins via __compiler_sodium_* runtime (#13078). */
final class JitSodium
{
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
}
