<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for parse_ini_string() / parse_ini_file() — compile-time materialize or NestedJIT runtime (#26909, #30756).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_ini_string) / PHP_FUNCTION(parse_ini_file)
 */
final class JitParseIni
{
    private const HELPER_PATH = '/ext/standard/ParseIniNativeJitHelper.php';

    private const PARSE_INTO_NATIVE = 'PHPCompiler\\ext\\standard\\ParseIniNativeJitHelper::parseIntoNative';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_INTO_NATIVE,
    ];

    /**
     * Runtime subject: NestedJIT {@see ParseIniNativeJitHelper} into a native HT (#26909).
     */
    public static function parseRuntime(
        Context $context,
        JITVariable $ini,
        bool $processSections,
        int $scannerMode
    ): Value {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26909'
        );

        $iniStr = JitStringBuiltinArg::lowerTrimFamilyString($context, $ini, 'parse_ini_string', 0, 'ini_string');

        return self::parseNativeStringIntoValue($context, $iniStr, $processSections, $scannerMode);
    }

    /**
     * Runtime filename: libc/helper file read then {@see parseNativeStringIntoValue} (#30756).
     *
     * Missing path: php-src `parse_ini_file(%s): Failed to open stream` Warning + false.
     */
    public static function parseRuntimeFile(
        Context $context,
        Value $pathStr,
        bool $processSections,
        int $scannerMode
    ): Value {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26909'
        );

        // Libc leaf — do not re-enter FileGetContentsJitHelper (#29833).
        $contents = JitFileGetContentsLibc::call($context, $pathStr);
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $failBlock = BasicBlockHelper::append($context, 'parse_ini_file_fail');
        $okBlock = BasicBlockHelper::append($context, 'parse_ini_file_ok');
        $doneBlock = BasicBlockHelper::append($context, 'parse_ini_file_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitBuiltinWarning::emitStreamOpenFailed($context, $pathStr, 'parse_ini_file');
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $okPtr = self::parseNativeStringIntoValue($context, $contents, $processSections, $scannerMode);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($okPtr, $okEnd);
        $result->addIncoming($failPtr, $failBlock);

        return $result;
    }

    /** NestedJIT {@see ParseIniNativeJitHelper} into a native HT; $iniStr is already `__string__*`. */
    public static function parseNativeStringIntoValue(
        Context $context,
        Value $iniStr,
        bool $processSections,
        int $scannerMode
    ): Value {
        $ht = HashTableHelper::alloc($context);
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $ht);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_INTO_NATIVE, '#26909');
        $iniArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $iniStr,
            $helperFn->getParam(1)->typeOf()
        );
        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $helperFn,
            $destI64,
            $iniArg,
            $i64->constInt($processSections ? 1 : 0, false),
            $i64->constInt($scannerMode, false)
        );

        $okTy = $ok->typeOf();
        $zero = $okTy->constInt(0, false);
        $okI1 = $context->builder->icmp(Builder::INT_NE, $ok, $zero);

        $okBlock = BasicBlockHelper::append($context, 'parse_ini_rt_ok');
        $failBlock = BasicBlockHelper::append($context, 'parse_ini_rt_fail');
        $doneBlock = BasicBlockHelper::append($context, 'parse_ini_rt_done');
        $context->builder->branchIf($okI1, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($okPtr, $okBlock);
        $result->addIncoming($failPtr, $failBlock);

        return $result;
    }

    /** Register phpc_native_ht_* Internal JIT handlers before NestedJIT (#13900 / #26909). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new phpc_native_ht_alloc(),
            new phpc_native_ht_set_string_key(),
            new phpc_native_ht_set_string_key_ht(),
            new phpc_native_ht_set_string_key_long(),
            new phpc_native_ht_set_string_at(),
            new phpc_native_ht_set_long_at(),
            new phpc_native_ht_set_hashtable_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }
}
