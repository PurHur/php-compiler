<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for symlink() via libc symlink(2) (#15544, #33415).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\SymlinkJitHelper} cannot create links under
 * thin AOT (host \\symlink re-enters; FFI unavailable in the native binary). Bridge body is
 * {@see SymlinkLibcRuntime} — peer {@see StringRmdir} #33403 / {@see StringMkdir} #33402.
 *
 * php-src: ext/standard/link.c — php_symlink
 */
final class StringSymlink
{
    private const ABI = '__phpc_jit_symlink';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $target, Value $link): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $target, $link);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#33415 / peer #33403) — clearInsertionPosition
        // left the user-script builder detached ("Instruction referencing instruction not embedded
        // in a basic block" / Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        SymlinkLibcRuntime::emit($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
