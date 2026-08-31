<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\VmValueBoxWriteBool;

/**
 * JIT trampoline for __value__writeBool (#9570).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteBool}
 *
 * Lazy from Value::implement via Context::lookupFunction (#36108 / peer #36100).
 */
final class ValueBoxWriteBoolJit
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeBool');
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
        VmValueBoxWriteBool::implement($context);
    }
}
