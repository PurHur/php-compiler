<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for substr_compare via SubstrCompareJitHelper PHP (#13536, #21816).
 *
 * Module-local owner after leftover Module.php always-on drop (#32402 / #32382 peer).
 * Replaces ~289 LOC LLVM in StringSubstrCompareJit.php. Keeps i8* ABI for callers.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringSubstrCount #21773).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 */
final class StringSubstrCompare
{
    private const HELPER_PATH = '/ext/standard/SubstrCompareJitHelper.php';

    private const SUBSTR_COMPARE_HELPER = 'PHPCompiler\\ext\\standard\\SubstrCompareJitHelper::substrCompareArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SUBSTR_COMPARE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'substr_compare_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('substr_compare');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction('substr_compare', $probe);

            return;
        }

        // Restore caller insert block after bridge emit (#21515 / peer #21556) —
        // clearInsertionPosition left the user-script builder detached
        // ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#21816');
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = 'substr_compare';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $i64, $i64, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $hayStr = self::stringFromCstr($context, $fn->getParam(0));
        $needleStr = self::stringFromCstr($context, $fn->getParam(1));
        $offset = $fn->getParam(2);
        $length = $fn->getParam(3);
        $caseInsensitive = $context->builder->icmp(
            Builder::INT_NE,
            $fn->getParam(4),
            $i32->constInt(0, false)
        );
        $raw = $context->builder->call(
            self::helperFunction($context, self::SUBSTR_COMPARE_HELPER),
            $hayStr,
            $needleStr,
            $offset,
            $length,
            $caseInsensitive
        );
        $context->builder->returnValue($context->builder->trunc($raw, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function stringFromCstr(Context $context, Value $cstr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $null = $i8p->constNull();

        $emptySlot = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $cstr, $null);
        $ptr = $context->builder->select($isNull, $emptyPtr, $cstr);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $ptr);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $ptr
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#21816');
    }
}
