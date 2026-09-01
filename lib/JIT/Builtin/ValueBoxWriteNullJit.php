<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\VmValueBoxWriteNull;

/**
 * JIT trampoline for __value__writeNull.
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteNull}
 *
 * Lazy from Value::implement via Context::lookupFunction (#36124 / peer #36108).
 */
final class ValueBoxWriteNullJit
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeNull');
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
        VmValueBoxWriteNull::implement($context);
    }
}
