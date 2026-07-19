<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for get_browser() via GetBrowserJitHelper PHP (#11172).
 *
 * NestedJIT of GetBrowserJitHelper currently emits parentless path_is_readable
 * calls that fail module verify (#21109). Eager String_::implement uses a thin
 * stub (browscap not configured). Full NestedJIT remains available via
 * ensureLinked() when get_browser() is invoked and NestedJIT is fixed.
 *
 * php-src: ext/standard/browscap.c — PHP_FUNCTION(get_browser)
 */
final class GetBrowserRuntime
{
    private const ABI_NAME = '__compiler_get_browser_browscap_configured';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);
        $entry = $fn->appendBasicBlock('get_browser_stub_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction(self::ABI_NAME, $fn);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
