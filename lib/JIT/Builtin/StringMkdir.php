<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for mkdir() via thin libc mkdir(2) (#33402 / peer touch #28995).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\MkdirJitHelper} re-enters this ABI under AOT
 * when VmFsDirPure calls host \\mkdir(). Platform mkdir(2) is the justified thin path.
 * php-src: ext/standard/filestat.c — php_mkdir
 */
final class StringMkdir
{
    private const ABI = '__phpc_jit_mkdir';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path, Value $mode, Value $recursive): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $path,
            $mode,
            $recursive
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#33402 / peer StringChmod #19283) —
        // clearInsertionPosition alone orphans mid-emit mkdir() callsites (Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $i64, $i1)
            );

        MkdirLibcRuntime::emit($context, $fn);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
