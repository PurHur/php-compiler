<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for unlink() via thin libc unlink(2) (#33412 / peer mkdir #33402 / touch #28995).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\UnlinkJitHelper} re-enters this ABI under AOT
 * when VmFsUnlinkPure calls host \\unlink(). Platform unlink(2) is the justified thin path.
 * php-src: ext/standard/filestat.c — php_unlink
 */
final class StringUnlink
{
    private const ABI = '__phpc_jit_unlink';

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

        // Restore caller insert block after bridge emit (#33412 / peer StringChmod #19283) —
        // clearInsertionPosition alone orphans mid-emit unlink() callsites (Module.php:180).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr)
            );

        UnlinkLibcRuntime::emit($context, $fn);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
