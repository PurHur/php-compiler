<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gmgetdate via GmgetdateJitHelper PHP (#9181, #33952).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmDate}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(gmgetdate)
 */
final class StringGmgetdate
{
    private const ABI_NAME = '__compiler_gmgetdate';

    private const HELPER_PATH = '/ext/standard/GmgetdateJitHelper.php';

    private const GMGETDATE_HELPER = 'PHPCompiler\\ext\\standard\\GmgetdateJitHelper::gmgetdate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::GMGETDATE_HELPER];

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

        // Same insert-block discipline as StringMktime (#33952 / #33934).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        try {
            self::ensureJitHelperCompiled($context);
            $i64 = $context->getTypeFromString('int64');
            $valuePtr = $context->getTypeFromString('__value__*');
            HashtableValueOutJitBridge::implement(
                $context,
                self::ABI_NAME,
                'gmg',
                [$i64, $valuePtr],
                self::helperFunction($context),
                static fn (Context $ctx, LlvmFunction $fn): array => [$fn->getParam(0)]
            );
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, self::GMGETDATE_HELPER, '#9181');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#9181');
    }
}
