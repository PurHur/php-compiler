<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\VmValueBoxWriteLong;

/**
 * JIT trampoline for __value__writeLong.
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteLong}
 *
 * Lazy from Value::implement via Context::lookupFunction (#36135 / peer #36124).
 */
final class ValueBoxWriteLongJit
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeLong');
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
        VmValueBoxWriteLong::implement($context);
    }
}
