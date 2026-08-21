<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rmdir() via libc rmdir(2) (#15481, #33403).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\RmdirJitHelper} cannot remove directories under
 * thin AOT (host \\rmdir re-enters; FFI unavailable in the native binary). Bridge body is
 * {@see RmdirLibcRuntime} — peer {@see ChownRuntime} / {@see TouchLibcRuntime}.
 *
 * php-src: ext/standard/filestat.c — php_rmdir
 */
final class StringRmdir
{
    private const ABI = '__phpc_jit_rmdir';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#33403 / peer #19283) — clearInsertionPosition
        // left the user-script builder detached ("Instruction referencing instruction not embedded
        // in a basic block" / Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        RmdirLibcRuntime::emit($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
