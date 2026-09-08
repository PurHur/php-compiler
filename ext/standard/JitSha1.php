<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSha1;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM helpers for sha1() — native {@see StringSha1} / {@code phpc_sha1_r1} (#36388).
 *
 * Returns owning {@code __string__*} (hex or raw). {@see JitHashFile} boxes for
 * sha1_file()'s false-on-missing-path phi.
 * php-src: ext/standard/sha1.c — PHP_FUNCTION(sha1)
 */
final class JitSha1
{
    public static function digest(Context $context, Value $data, Value $raw): Value
    {
        $rawI32 = $context->builder->zExt($raw, $context->getTypeFromString('int32'));

        return StringSha1::invoke($context, $data, $rawI32);
    }
}
