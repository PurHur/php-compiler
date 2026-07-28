<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_idate via IdateJitHelper PHP (#9181, #24382).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringErrorLog #24094).
 * Replaces localtime/format-char LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate)
 */
final class StringIdate
{
    private const HELPER_PATH = '/ext/standard/IdateJitHelper.php';

    private const IDATE_HELPER = 'PHPCompiler\\ext\\standard\\IdateJitHelper::idate';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IDATE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $abiName = '__compiler_idate';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('idate_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::IDATE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24382');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24382'
        );
    }
}
