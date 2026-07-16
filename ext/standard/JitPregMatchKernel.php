<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;

/**
 * LLVM __compiler_preg_* stubs for user-script standalone AOT (#19399, #16075, #16734).
 *
 * Nested {@see PregJitHelper} segfaults after minimal standalone init; route preg_match
 * through thin LLVM bridges instead of nested PHP lowering until execute ABI is fixed.
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19389.
 * php-src: ext/pcre/php_pcre.c
 */
final class JitPregMatchKernel
{
    public static function implement(Context $context): void
    {
        LibcExtern::register($context);
        self::implementMatchStub($context);
        self::implementErrorStub($context, '__compiler_preg_match_all', 'preg_match_all_us_stub');
        self::implementErrorStub($context, '__compiler_preg_match_ex', 'preg_match_ex_us_stub');
        self::implementErrorStub($context, '__compiler_preg_match_all_ex', 'preg_match_all_ex_us_stub');
        self::implementNullPtrStub($context, '__compiler_preg_last_error_msg', 'preg_last_error_msg_us_stub');
        self::implementZeroStub($context, '__compiler_preg_last_error', 'preg_last_error_us_stub');
        self::implementNullPtrStub($context, '__compiler_preg_replace', 'preg_replace_us_stub');
        self::implementNullPtrStub($context, '__compiler_preg_replace_callback', 'preg_replace_cb_us_stub');
        self::implementNullPtrStub($context, '__compiler_preg_split', 'preg_split_us_stub');
    }

    private static function implementMatchStub(Context $context): void
    {
        $abiName = '__compiler_preg_match';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'preg_match_us_llvm_main')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('preg_match_us_llvm_main');
        $context->builder->positionAtEnd($entry);
        // TODO(#16075): literal memcmp search — execute still segfaults before echo even with this stub.
        $context->builder->returnValue($i64->constInt(1, false));
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementErrorStub(Context $context, string $abiName, string $entryName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($i64, false));
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementZeroStub(Context $context, string $abiName, string $entryName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($i64, false));
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNullPtrStub(Context $context, string $abiName, string $entryName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ret = \str_contains($abiName, 'split') ? $htPtr : $strPtr;
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($ret, false));
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($ret->constNull());
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }
}
