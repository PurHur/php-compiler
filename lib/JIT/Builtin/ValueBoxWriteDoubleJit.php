<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\VmValueBoxWriteDouble;

/**
 * JIT trampoline for __value__writeDouble.
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteDouble}
 *
 * Lazy from Value::implement via Context::lookupFunction (#36141 / peer #36135 writeLong).
 */
final class ValueBoxWriteDoubleJit
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeDouble');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $restore = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            NestedJitCompileScope::run($context, static function () use ($context): void {
                self::implement($context);
            });
        } finally {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    public static function implement(Context $context): void
    {
        VmValueBoxWriteDouble::implement($context);
    }
}
