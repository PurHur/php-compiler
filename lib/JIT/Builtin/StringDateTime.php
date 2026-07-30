<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_format_datetime via FormatDatetimeJitHelper PHP (#15243, #25433).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringStrftime #25365 / StringStrptime #25409).
 * Replaces gmtime/localtime/format-char LLVM monolith; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date), PHP_FUNCTION(gmdate)
 */
final class StringDateTime
{
    private const HELPER_PATH = '/ext/standard/FormatDatetimeJitHelper.php';

    private const FORMAT_DATETIME_HELPER = 'PHPCompiler\\ext\\standard\\FormatDatetimeJitHelper::formatDatetimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_DATETIME_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_format_datetime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementFormatDatetimeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementFormatDatetimeBridge(Context $context): void
    {
        $abiName = '__compiler_format_datetime';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('format_datetime_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FORMAT_DATETIME_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25433');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25433'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_format_datetime');
        if (null === $fn) {
            throw new \LogicException('__compiler_format_datetime missing after StringDateTime bridge (#15243/#25433)');
        }
        $context->registerFunction('__compiler_format_datetime', $fn);
    }
}
