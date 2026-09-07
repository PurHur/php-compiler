<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringMd5;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM helpers for md5() — native {@see StringMd5} / {@code phpc_md5_r1} (#36388).
 *
 * Returns owning {@code __string__*} (hex or raw). {@see JitHashFile} boxes for
 * md5_file()'s false-on-missing-path phi.
 * php-src: ext/standard/md5.c — PHP_FUNCTION(md5)
 */
final class JitMd5
{
    public static function digest(Context $context, Value $data, Value $raw): Value
    {
        $rawI32 = $context->builder->zExt($raw, $context->getTypeFromString('int32'));

        return StringMd5::invoke($context, $data, $rawI32);
    }
}
