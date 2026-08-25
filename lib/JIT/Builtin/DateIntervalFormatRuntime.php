<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_date_interval_format via DateIntervalFormatJitHelper (#9499, #25121, #33203).
 *
 * getNamedFunction-first so leftover Type decls cannot mint date_interval_format.1 (#31894 / #32122).
 * Save/restore insert block around ensureLinked — mid-main format after DateTime::diff (#33912).
 * NestedJIT scalar args coerced via {@see JitNestedHelperCoerce} (#34599).
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
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
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreCallerInsert($context, $savedInsert);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFormatBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreCallerInsert($context, $savedInsert);
    }

    private static function restoreCallerInsert(Context $context, ?BasicBlock $savedInsert): void
    {
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
            $i64,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $helperFn = self::helperFunction($context, self::FORMAT_HELPER);
        $entry = $fn->appendBasicBlock('di_fmt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // NestedJIT may box scalars as __value__* — coerce every ABI arg (#34599 / peer DomLoad).
        $bridgeArgs = [];
        for ($i = 0; $i < 11; ++$i) {
            $bridgeArgs[] = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $result = $context->builder->call($helperFn, ...$bridgeArgs);
        $ret = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr);
        $context->builder->returnValue($ret);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
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
