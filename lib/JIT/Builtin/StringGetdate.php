<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getdate via GetdateJitHelper PHP (#9181).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmDate}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getdate)
 */
final class StringGetdate
{
    private const ABI_NAME = '__compiler_getdate';

    private const HELPER_PATH = '/ext/standard/GetdateJitHelper.php';

    private const GETDATE_HELPER = 'PHPCompiler\\ext\\standard\\GetdateJitHelper::getdate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::GETDATE_HELPER];

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

        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        HashtableValueOutJitBridge::implement(
            $context,
            self::ABI_NAME,
            'gd',
            [$i64, $valuePtr],
            self::helperFunction($context),
            static fn (Context $ctx, LlvmFunction $fn): array => [$fn->getParam(0)]
        );
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, self::GETDATE_HELPER, '#9181');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#9181');
    }
}
