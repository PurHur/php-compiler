<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM helpers for md5() — fixed algorithm via __compiler_hash. */
final class JitMd5
{
    public static function digest(Context $context, Value $data, Value $raw): Value
    {
        StringHashCrypto::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $len = $context->getTypeFromString('int64')->constInt(3, false);
        $algo = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($context->constantFromString('md5'), $i8p)
        );

        return JitHash::hash($context, $algo, $data, $raw);
    }
}
