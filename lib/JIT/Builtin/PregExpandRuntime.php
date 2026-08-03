<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_preg_expand_replacement via PregExpandJitHelper PHP (#10064, #27456).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiledBundle} (peer PregEmptyPatternReplaceRuntime #27432).
 * Replaces lib/AOT/runtime/phpc_preg_expand.c. SSOT: {@see \PHPCompiler\ext\standard\PregReplacementExpand}.
 * php-src: ext/pcre/php_pcre.c — php_pcre_replace_impl replacement parsing
 */
final class PregExpandRuntime
{
    private const HELPER_PATH = '/ext/standard/PregExpandJitHelper.php';

    private const CORE_PATH = '/ext/standard/PregReplacementExpand.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::CORE_PATH,
        self::HELPER_PATH,
    ];

    private const EXPAND_HELPER = 'PHPCompiler\\ext\\standard\\PregExpandJitHelper::expand';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXPAND_HELPER,
    ];

    private const ABI_NAME = 'phpc_preg_expand_replacement';

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
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        self::ensureLibc($context);
        self::ensureValueStringHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementExpandBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementExpandBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType(
            $sizeT,
            false,
            $i8p,
            $sizeT,
            $sizeTp,
            $i32,
            $i8p,
            $i8p,
            $sizeT
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('preg_expand_entry');
        $nullOut = $fn->appendBasicBlock('preg_expand_null_out');
        $checkOvector = $fn->appendBasicBlock('preg_expand_check_ovector');
        $literalCopy = $fn->appendBasicBlock('preg_expand_literal_copy');
        $literalOk = $fn->appendBasicBlock('preg_expand_literal_ok');
        $literalFail = $fn->appendBasicBlock('preg_expand_literal_fail');
        $expandBody = $fn->appendBasicBlock('preg_expand_body');

        $context->builder->positionAtEnd($entry);
        $repl = $fn->getParam(0);
        $replLen = $fn->getParam(1);
        $ovector = $fn->getParam(2);
        $ovectorCount = $fn->getParam(3);
        $subj = $fn->getParam(4);
        $out = $fn->getParam(5);
        $outCap = $fn->getParam(6);

        $nullPtr = $i8p->constNull();
        $zeroCap = $context->builder->icmp(
            Builder::INT_EQ,
            $outCap,
            $sizeT->constInt(0, false)
        );
        $replNull = $context->builder->icmp(Builder::INT_EQ, $repl, $nullPtr);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullPtr);
        $badInput = $context->builder->or(
            $replNull,
            $context->builder->or($outNull, $zeroCap)
        );
        $context->builder->branchIf($badInput, $nullOut, $checkOvector);

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnValue($sizeT->constInt(0, false));

        $context->builder->positionAtEnd($checkOvector);
        $ovectorNull = $context->builder->icmp(Builder::INT_EQ, $ovector, $nullPtr);
        $subjNull = $context->builder->icmp(Builder::INT_EQ, $subj, $nullPtr);
        $needsLiteral = $context->builder->or($ovectorNull, $subjNull);
        $context->builder->branchIf($needsLiteral, $literalCopy, $expandBody);

        $context->builder->positionAtEnd($literalCopy);
        $fits = $context->builder->icmp(Builder::INT_ULT, $replLen, $outCap);
        $context->builder->branchIf($fits, $literalOk, $literalFail);
        $context->builder->positionAtEnd($literalFail);
        $context->builder->returnValue($sizeT->constInt(0, false));
        $context->builder->positionAtEnd($literalOk);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $out,
            $repl,
            $replLen
        );
        $context->builder->returnValue($replLen);

        $context->builder->positionAtEnd($expandBody);
        $replStr = self::bytesToString($context, $repl, $replLen);
        $subjLen = $context->builder->call($context->lookupFunction('strlen'), $subj);
        $subjStr = self::bytesToString(
            $context,
            $subj,
            $context->builder->trunc($subjLen, $context->getTypeFromString('int64'))
        );
        $packedLen = $context->builder->mul(
            $context->builder->zExt($ovectorCount, $sizeT),
            $sizeT->constInt(16, false)
        );
        $packedBuf = $context->builder->call($context->lookupFunction('malloc'), $packedLen);
        $packedNull = $fn->appendBasicBlock('preg_expand_packed_null');
        $packedWork = $fn->appendBasicBlock('preg_expand_packed_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $packedBuf, $nullPtr),
            $packedNull,
            $packedWork
        );
        $context->builder->positionAtEnd($packedNull);
        $context->builder->returnValue($sizeT->constInt(0, false));
        $context->builder->positionAtEnd($packedWork);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $packedBuf,
            $context->builder->pointerCast($ovector, $i8p),
            $packedLen
        );
        $packedStr = self::bytesToString(
            $context,
            $context->builder->pointerCast($packedBuf, $i8p),
            $context->builder->trunc($packedLen, $context->getTypeFromString('int64'))
        );
        $expandedStr = $context->builder->call(
            self::helperFunction($context, self::EXPAND_HELPER),
            $replStr,
            $packedStr,
            $ovectorCount,
            $subjStr
        );
        $context->builder->call($context->lookupFunction('free'), $packedBuf);

        $strMap = $context->structFieldMap['__string__'];
        $expandedLen = $context->builder->load(
            $context->builder->structGep($expandedStr, $strMap['length'])
        );
        $expandedData = $context->builder->pointerCast(
            $context->builder->structGep($expandedStr, $strMap['value']),
            $i8p
        );
        $expandedLenSize = $context->builder->trunc($expandedLen, $sizeT);
        $hasExpanded = $context->builder->icmp(
            Builder::INT_UGT,
            $expandedLenSize,
            $sizeT->constInt(0, false)
        );
        $copyExpanded = $fn->appendBasicBlock('preg_expand_copy_expanded');
        $returnZero = $fn->appendBasicBlock('preg_expand_return_zero');
        $context->builder->branchIf($hasExpanded, $copyExpanded, $returnZero);
        $context->builder->positionAtEnd($returnZero);
        $context->builder->returnValue($sizeT->constInt(0, false));
        $context->builder->positionAtEnd($copyExpanded);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $expandedLenSize, $outCap),
            $expandedLenSize,
            $context->builder->sub($outCap, $sizeT->constInt(1, false))
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $out,
            $expandedData,
            $copyLen
        );
        $context->builder->returnValue($copyLen);

        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function bytesToString(Context $context, Value $bytes, Value $lenI64): Value
    {
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($bytes, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27456');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#27456'
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $i8p, [$sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }
}
