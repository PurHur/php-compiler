<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strcoll via StrcollJitHelper PHP (#13566 phase 2, #22256).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer CopyRuntime #22231).
 * PHP bridge uses `__compiler_strcoll` so AOT does not export libc `strcoll` (#26861).
 * SSOT: {@see \PHPCompiler\ext\standard\VmLocaleCollate}
 */
final class StringStrcoll
{
    private const HELPER_PATH = '/ext/standard/StrcollJitHelper.php';

    private const STRCOLL_HELPER = 'PHPCompiler\\ext\\standard\\StrcollJitHelper::strcollArgv';

    public const ABI_STRCOLL = '__compiler_strcoll';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCOLL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementNamed($context, self::ABI_STRCOLL, self::STRCOLL_HELPER);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implementNamed(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBinaryBridge($context, $abiName, $helperLogical);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBinaryBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strcoll_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $aStr = self::stringFromCstr($context, $fn->getParam(0));
        $bStr = self::stringFromCstr($context, $fn->getParam(1));
        $raw = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $aStr,
            $bStr
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
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StrcollJitHelper compile (#13566)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22256'
        );
    }
}
