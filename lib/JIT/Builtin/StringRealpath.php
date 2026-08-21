<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __phpc_jit_realpath via thin libc realpath(3) (#33432 / peer #33287).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\RealpathJitHelper} returns empty under thin AOT
 * (DirectoryIterator already used module-local realpath(3) in #33287). Platform realpath(3)
 * is the justified thin path — peer SysGetTempDirRuntime (#29433) / StringUnlink (#33412).
 * php-src: ext/standard/basic_functions.c — php_realpath
 */
final class StringRealpath
{
    private const ABI = '__phpc_jit_realpath';

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

        // Restore caller insert block after bridge emit (#33432 / peer StringChmod #33418) —
        // clearInsertionPosition alone orphans mid-emit realpath() callsites (Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        RealpathLibcRuntime::emit($context, $fn);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
