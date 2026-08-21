<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chmod() via thin libc chmod(2) (#33418 / peer unlink #33412 / mkdir #33402).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\ChmodJitHelper} re-enters this ABI under AOT
 * when VmFsDirPure calls host \\chmod(). Platform chmod(2) is the justified thin path.
 * php-src: ext/standard/filestat.c — php_chmod
 */
final class StringChmod
{
    private const ABI = '__phpc_jit_chmod';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path, Value $mode): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path, $mode);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#33418 / peer StringUnlink #33412) —
        // clearInsertionPosition alone orphans mid-emit chmod() callsites (Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $i32)
            );

        ChmodLibcRuntime::emit($context, $fn);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
