<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_date_interval_format via DateIntervalFormatJitHelper PHP (#9499, #25121, #33203).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer TimezoneOffset #25042 / Mktime #25116).
 * Declares module-locally (getNamedFunction first) so leftover Type empty decls cannot mint
 * date_interval_format.1 (#31894 / #32122 / #33203).
 * Thin LLVM bridge forwards the ABI. php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
 *
 * Mid-main ensureLinked must restore the caller's insert block (#33912 / peer #27406 / #27550).
 */
final class DateIntervalFormatRuntime
{
    private const HELPER_PATH = '/ext/standard/DateIntervalFormatJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\DateIntervalFormatJitHelper::formatFromScalars';

    private const ABI_NAME = '__compiler_date_interval_format';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementFormatBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementFormatBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('di_fmt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::FORMAT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4),
            $fn->getParam(5),
            $fn->getParam(6),
            $fn->getParam(7),
            $fn->getParam(8),
            $fn->getParam(9),
            $fn->getParam(10)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25121');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25121'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after DateIntervalFormatRuntime bridge (#11518)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
