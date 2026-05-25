<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM helpers for sha1() — fixed algorithm via __compiler_hash. */
final class JitSha1
{
    public static function digest(Context $context, Value $data, Value $raw): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->getTypeFromString('int64')->constInt(4, false);
        $algo = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($context->constantFromString('sha1'), $i8p)
        );

        return JitHash::hash($context, $algo, $data, $raw);
    }
}
