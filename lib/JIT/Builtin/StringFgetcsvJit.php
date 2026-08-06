<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fgetcsv via fgets + CsvStrGetcsvJitHelper (#6750, #9444, #13440, #27180).
 *
 * Thin AOT must not NestedJIT CsvJitHelper fgetcsvArgv
 * (VmFs::fgetcsv / builtinHandlerFrame missing under NestedJIT). Read one line via
 * {@see __compiler_fgets} (libc FILE* under thin AOT) then parse with the NestedJIT-safe
 * {@see \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper} (#27069).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fgetcsv)
 */
final class StringFgetcsvJit
{
    private const FGETCSV_PARSE_HELPER = 'PHPCompiler\\ext\\standard\\CsvStrGetcsvJitHelper::strGetcsvArgv';

    private const STRIP_LINE_HELPER = 'PHPCompiler\\ext\\standard\\CsvStrGetcsvJitHelper::stripLineTerminatorsArgv';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_fgetcsv',
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FGETCSV_PARSE_HELPER,
        self::STRIP_LINE_HELPER,
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fgetcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringStrGetcsv::ensureLinked($context);
        StreamReadRuntime::ensureLinked($context);
        self::ensureStripHelperCompiled($context);
        self::implementFgetcsvBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementFgetcsvBridge(Context $context): void
    {
        $abiName = '__compiler_fgetcsv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i64, $i64, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fgetcsv_bridge_entry');
        $bodyBb = $fn->appendBasicBlock('fgetcsv_bridge_body');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $separator = $fn->getParam(2);
        $enclosure = $fn->getParam(3);
        $escape = $fn->getParam(4);

        // php-src: length <= 0 means no limit; fgets ABI uses length as max (incl. NUL).
        // Use a large positive cap when length < 1 (peer VmFs::fgetcsv null length).
        $zero = $i64->constInt(0, false);
        $defaultCap = $i64->constInt(8192, false);
        $useDefault = $context->builder->icmp(Builder::INT_SLT, $length, $i64->constInt(1, false));
        $fgetsLen = $context->builder->select($useDefault, $defaultCap, $length);

        $lineRaw = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            $fgetsLen
        );
        $lineIsNull = $context->builder->icmp(Builder::INT_EQ, $lineRaw, $strPtr->constNull());
        $eofBb = $fn->appendBasicBlock('fgetcsv_bridge_eof');
        $parseBb = $fn->appendBasicBlock('fgetcsv_bridge_parse');
        $context->builder->branchIf($lineIsNull, $eofBb, $parseBb);

        $context->builder->positionAtEnd($eofBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($parseBb);
        $lineSep = $context->builder->call($context->lookupFunction('__string__separate'), $lineRaw);
        $strippedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::stripHelperFunction($context),
            [$lineSep]
        );
        $stripped = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strippedRaw);
        $strippedSep = $context->builder->call($context->lookupFunction('__string__separate'), $stripped);
        $sepSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $separator, ',');
        $encSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $enclosure, '"');
        $escSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $escape, '\\');
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::parseHelperFunction($context),
            [$strippedSep, $sepSep, $encSep, $escSep]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        // Line-terminator-only / empty helper [] → synthesize [null] (#27069 / #10623).
        $sizeT = $context->getTypeFromString('size_t');
        $htIsNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $num = $context->builder->select(
            $htIsNull,
            $sizeT->constInt(0, false),
            $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht)
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('fgetcsv_bridge_empty_null_row');
        $retBb = $fn->appendBasicBlock('fgetcsv_bridge_ret');
        $context->builder->branchIf($isEmpty, $emptyBb, $retBb);

        $context->builder->positionAtEnd($emptyBb);
        $nullRow = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setNullAt'),
            $nullRow,
            $sizeT->constInt(0, false)
        );
        $context->builder->returnValue($nullRow);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function parseHelperFunction(Context $context): LlvmFunction
    {
        StringStrGetcsv::ensureLinked($context);
        self::ensureStripHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::FGETCSV_PARSE_HELPER, '#27180');
    }

    private static function stripHelperFunction(Context $context): LlvmFunction
    {
        self::ensureStripHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::STRIP_LINE_HELPER, '#27180');
    }

    private static function ensureStripHelperCompiled(Context $context): void
    {
        // StringStrGetcsv NestedJITs strGetcsvArgv only; also compile stripLineTerminatorsArgv.
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            ['/ext/standard/CsvStrGetcsvJitHelper.php'],
            self::COMPILED_HELPERS,
            '#27180',
            true
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFgetcsvJit bridge (#27180)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
