<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_localtime via LocaltimeJitHelper PHP (#9181).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmDate}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(localtime)
 */
final class StringLocaltime
{
    private const ABI_NAME = '__compiler_localtime';

    private const HELPER_PATH = '/ext/standard/LocaltimeJitHelper.php';

    private const LOCALTIME_HELPER = 'PHPCompiler\\ext\\standard\\LocaltimeJitHelper::localtime';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::LOCALTIME_HELPER];

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
        $i1 = $context->getTypeFromString('int1');
        $valuePtr = $context->getTypeFromString('__value__*');
        HashtableValueOutJitBridge::implement(
            $context,
            self::ABI_NAME,
            'lt',
            [$i64, $i1, $valuePtr],
            self::helperFunction($context),
            static fn (Context $ctx, LlvmFunction $fn): array => [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, self::LOCALTIME_HELPER, '#9181');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#9181');
    }
}
