<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\VmValueCopy;

/**
 * JIT trampoline for __value__copy.
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueCopy}
 *
 * Lazy from JitValueBox::copyBetweenPointers via Context::lookupFunction (#36193).
 */
final class ValueBoxCopyJit
{
    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__copy');
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
        VmValueCopy::implement($context);
    }
}
