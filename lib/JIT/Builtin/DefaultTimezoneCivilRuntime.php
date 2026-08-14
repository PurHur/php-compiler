<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_default_tz_civil_timestamp via DefaultTimezoneCivilJitHelper (#31047).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\DefaultTimezoneCivilJitHelper}
 * php-src: ext/date/lib/timelib.c — timelib_unixtime2local for procedural getdate/idate
 */
final class DefaultTimezoneCivilRuntime
{
    private const ABI_NAME = '__compiler_default_tz_civil_timestamp';

    private const HELPER_PATH = '/ext/standard/DefaultTimezoneCivilJitHelper.php';

    private const CIVIL_HELPER = 'PHPCompiler\\ext\\standard\\DefaultTimezoneCivilJitHelper::localCivilTimestamp';

    private const IS_DST_HELPER = 'PHPCompiler\\ext\\standard\\DefaultTimezoneCivilJitHelper::localIsDst';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CIVIL_HELPER,
        self::IS_DST_HELPER,
    ];

    public static function ensureLinked(Context $context): void
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
        self::implementBridge($context);
        self::implementIsDstBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('dtz_civil_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $timestamp = $fn->getParam(0);
        $civil = $context->builder->call(
            self::helperFunction($context),
            $timestamp
        );
        $context->builder->returnValue($civil);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function implementIsDstBridge(Context $context): void
    {
        $abiName = '__compiler_default_tz_is_dst';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dtz_isdst_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $timestamp = $fn->getParam(0);
        $isdst = $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, self::IS_DST_HELPER, '#31047'),
            $timestamp
        );
        $context->builder->returnValue($isdst);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CIVIL_HELPER, '#31047');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31047'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after DefaultTimezoneCivilRuntime bridge (#31047)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
        $isDstFn = $context->module->getNamedFunction('__compiler_default_tz_is_dst');
        if (null === $isDstFn || 0 === $isDstFn->countBasicBlocks()) {
            throw new \LogicException('__compiler_default_tz_is_dst missing after DefaultTimezoneCivilRuntime bridge (#31047)');
        }
        $context->registerFunction('__compiler_default_tz_is_dst', $isDstFn);
    }
}
