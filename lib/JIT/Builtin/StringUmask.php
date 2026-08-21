<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value;

/**
 * JIT/AOT link for umask() via libc umask(2) (#15497, #33422).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\UmaskJitHelper} cannot call host \\umask()
 * under thin AOT (re-enters phpc_umask_*). Bridge bodies are {@see UmaskLibcRuntime} —
 * peer {@see StringUnlink} #33412 / {@see StringRmdir} #33403.
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(umask)
 */
final class StringUmask
{
    private const ABI_GET = 'phpc_umask_get';

    private const ABI_SET = 'phpc_umask_set';

    public static function ensureLinked(Context $context): void
    {
        self::implementGet($context);
        self::implementSet($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, ?Value $maskLong): Value
    {
        self::ensureLinked($context);
        if (null === $maskLong) {
            return $context->builder->call($context->lookupFunction(self::ABI_GET));
        }

        $maskI64 = JitNestedHelperCoerce::scalarToI64($context, $maskLong, $maskLong->typeOf());

        return $context->builder->call($context->lookupFunction(self::ABI_SET), $maskI64);
    }

    private static function implementGet(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_GET);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_GET, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_GET,
                $context->context->functionType($i64, false)
            );

        UmaskLibcRuntime::emitGet($context, $fn);
        $context->registerFunction(self::ABI_GET, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementSet(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SET);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_SET, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_SET,
                $context->context->functionType($i64, false, $i64)
            );

        UmaskLibcRuntime::emitSet($context, $fn);
        $context->registerFunction(self::ABI_SET, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
