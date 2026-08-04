<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for date_default_timezone_get/set via DefaultTimezoneJitHelper PHP (#9243, #24962).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer TimezoneLocation #24801).
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: "Current basic block has no parent function", #27550 / peer #27088).
 * Replaces phpc_default_timezone_* LLVM globals + zoneinfo access walk.
 * SSOT: {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_default_timezone_get/set)
 */
final class DefaultTimezoneRuntime
{
    private const HELPER_PATH = '/ext/standard/DefaultTimezoneJitHelper.php';

    private const GET_HELPER = 'PHPCompiler\\ext\\standard\\DefaultTimezoneJitHelper::defaultTimezoneGet';

    private const SET_HELPER = 'PHPCompiler\\ext\\standard\\DefaultTimezoneJitHelper::tryDefaultTimezoneSet';

    private const NOTICE_HELPER = 'PHPCompiler\\ext\\standard\\DefaultTimezoneJitHelper::emitInvalidTimezoneNotice';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_HELPER,
        self::SET_HELPER,
        self::NOTICE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_default_timezone_get');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27550 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementGetBridge($context);
        self::implementSetBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetBridge(Context $context): void
    {
        $abiName = '__compiler_default_timezone_get';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dtz_get_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('dtz_get_null_out');
        $bodyBb = $fn->appendBasicBlock('dtz_get_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $tzStr = $context->builder->call(self::helperFunction($context, self::GET_HELPER));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $tzStr
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetBridge(Context $context): void
    {
        $abiName = '__compiler_default_timezone_set';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('dtz_set_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('dtz_set_null_out');
        $bodyBb = $fn->appendBasicBlock('dtz_set_body');
        $context->builder->positionAtEnd($entry);

        $tz = $fn->getParam(0);
        $out = $fn->getParam(1);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $ok = $context->builder->call(self::helperFunction($context, self::SET_HELPER), $tz);
        $failBb = $fn->appendBasicBlock('dtz_set_fail');
        $storeBb = $fn->appendBasicBlock('dtz_set_store');
        $context->builder->branchIf($ok, $storeBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(self::helperFunction($context, self::NOTICE_HELPER), $tz);
        $context->builder->branch($storeBb);

        $context->builder->positionAtEnd($storeBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $context->builder->zext($ok, $i32)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24962');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24962'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_default_timezone_get', '__compiler_default_timezone_set'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after DefaultTimezoneRuntime bridge (#9243)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
