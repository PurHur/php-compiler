<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strcasecmp/strncasecmp via CaseCompareJitHelper PHP (#15225, #23862).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringStrtotime #23832).
 * PHP bridges use `__compiler_strcasecmp` / `__compiler_strncasecmp` so AOT does not
 * export libc-named symbols that interpose into libxcrypt (#26861). Keeps i8* ABI.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 */
final class StringCaseCompare
{
    private const HELPER_PATH = '/ext/standard/CaseCompareJitHelper.php';

    private const STRCASECMP_HELPER = 'PHPCompiler\\ext\\standard\\CaseCompareJitHelper::strcasecmpArgv';

    private const STRNCASECMP_HELPER = 'PHPCompiler\\ext\\standard\\CaseCompareJitHelper::strncasecmpArgv';

    public const ABI_STRCASECMP = '__compiler_strcasecmp';

    public const ABI_STRNCASECMP = '__compiler_strncasecmp';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRCASECMP_HELPER,
        self::STRNCASECMP_HELPER,
    ];

    public static function ensureStrcasecmpLinked(Context $context): void
    {
        self::implementBinaryNamed($context, self::ABI_STRCASECMP, self::STRCASECMP_HELPER);
    }

    public static function ensureStrncasecmpLinked(Context $context): void
    {
        self::implementTernaryNamed($context, self::ABI_STRNCASECMP, self::STRNCASECMP_HELPER);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureStrcasecmpLinked($context);
        self::ensureStrncasecmpLinked($context);
    }

    private static function implementBinaryNamed(Context $context, string $abiName, string $helperLogical): void
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

    private static function implementTernaryNamed(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementTernaryBridge($context, $abiName, $helperLogical);
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

        $entry = $fn->appendBasicBlock('strcasecmp_bridge_entry');
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

    private static function implementTernaryBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strncasecmp_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $aStr = self::stringFromCstr($context, $fn->getParam(0));
        $bStr = self::stringFromCstr($context, $fn->getParam(1));
        $len = $fn->getParam(2);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($context->builder->trunc($len, $i32), $i64);
        $raw = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $aStr,
            $bStr,
            $lenI64
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
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23862');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23862'
        );
    }
}
