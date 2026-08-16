<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for ftok() — thin libc ftok(3) (#31478).
 *
 * Used while NestedJIT compiles {@see FtokJitHelper} `@ftok` via
 * {@see \PHPCompiler\JIT\Builtin\FtokRuntime} (posix_getpid #30696 / getmypid #30623 shape).
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok)
 */
final class JitFtokKernel
{
    /**
     * @param Value $pathStr __string__* (non-null; callers validate)
     * @param Value $projId  i32 project id byte
     *
     * @return Value i64 — System V key, or -1 on failure
     */
    public static function invoke(Context $context, Value $pathStr, Value $projId): Value
    {
        self::ensureLibcFtok($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $map = $context->structFieldMap['__string__'];
        $pathC = $context->builder->structGep($pathStr, $map['value']);
        $pathC = $pathC->typeOf() === $i8p
            ? $pathC
            : $context->builder->pointerCast($pathC, $i8p);
        $proj32 = $projId->typeOf() === $i32
            ? $projId
            : $context->builder->trunc($projId, $i32);
        $raw = $context->builder->call($context->lookupFunction('ftok'), $pathC, $proj32);

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->sext($raw, $i64);
    }

    private static function ensureLibcFtok(Context $context): void
    {
        try {
            $context->lookupFunction('ftok');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $i8p = $context->getTypeFromString('int8*');
            $fn = $context->module->addFunction(
                'ftok',
                $context->context->functionType($i32, false, $i8p, $i32)
            );
            $context->registerFunction('ftok', $fn);
        }
    }
}
