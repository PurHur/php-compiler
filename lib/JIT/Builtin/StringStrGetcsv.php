<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_str_getcsv via CsvStrGetcsvJitHelper PHP (#9444, #13358, #26135, #27069).
 *
 * Helper compile: NestedJIT {@see CsvStrGetcsvJitHelper} only (no VmFs / fgetcsvArgv).
 * Bridge: default CSV chars via {@see Context::constantStringFromString} (not raw cstr →
 * `__string__separate`), and NestedJIT HashTable returns via {@see JitNestedHelperCoerce}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_getcsv)
 */
final class StringStrGetcsv
{
    private const HELPER_PATH = '/ext/standard/CsvStrGetcsvJitHelper.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::HELPER_PATH,
    ];

    private const STR_GETCSV_HELPER = 'PHPCompiler\\ext\\standard\\CsvStrGetcsvJitHelper::strGetcsvArgv';

    private const STRIP_LINE_HELPER = 'PHPCompiler\\ext\\standard\\CsvStrGetcsvJitHelper::stripLineTerminatorsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STR_GETCSV_HELPER,
        self::STRIP_LINE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_str_getcsv',
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
        $probe = $context->module->getNamedFunction('__compiler_str_getcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementStrGetcsvBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStrGetcsvBridge(Context $context): void
    {
        $abiName = '__compiler_str_getcsv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('str_getcsv_bridge_entry');
        $nullBb = $fn->appendBasicBlock('str_getcsv_bridge_null');
        $bodyBb = $fn->appendBasicBlock('str_getcsv_bridge_body');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $separator = $fn->getParam(1);
        $enclosure = $fn->getParam(2);
        $escape = $fn->getParam(3);

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $input, $strPtr->constNull()),
            $nullBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($bodyBb);
        $inputSep = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        // Empty / whitespace-free zero-length input → [null] without NestedJIT (return [null]
        // aborts; empty-array helper + setNullAt left count() aborting — #27069).
        $map = $context->structFieldMap['__string__'];
        $inLen = $context->builder->load($context->builder->structGep($inputSep, $map['length']));
        $sizeT = $context->getTypeFromString('size_t');
        $isZeroLen = $context->builder->icmp(Builder::INT_EQ, $inLen, $sizeT->constInt(0, false));
        $zeroLenBb = $fn->appendBasicBlock('str_getcsv_bridge_zero_len');
        $parseBb = $fn->appendBasicBlock('str_getcsv_bridge_parse');
        $context->builder->branchIf($isZeroLen, $zeroLenBb, $parseBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $context->builder->returnValue(self::allocNullRowHashtable($context));

        $context->builder->positionAtEnd($parseBb);
        // php-src / fgetcsv bridge — strip trailing CR/LF before NestedJIT parse (#28994).
        // Helper also strips (VmCsv parity for host/JIT); double-strip is a no-op.
        $strippedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STRIP_LINE_HELPER),
            [$inputSep]
        );
        $stripped = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strippedRaw);
        $strippedSep = $context->builder->call($context->lookupFunction('__string__separate'), $stripped);
        $stripLen = $context->builder->load($context->builder->structGep($strippedSep, $map['length']));
        $stripZero = $context->builder->icmp(Builder::INT_EQ, $stripLen, $sizeT->constInt(0, false));
        $stripZeroBb = $fn->appendBasicBlock('str_getcsv_bridge_strip_zero');
        $stripParseBb = $fn->appendBasicBlock('str_getcsv_bridge_strip_parse');
        $context->builder->branchIf($stripZero, $stripZeroBb, $stripParseBb);

        $context->builder->positionAtEnd($stripZeroBb);
        $context->builder->returnValue(self::allocNullRowHashtable($context));

        $context->builder->positionAtEnd($stripParseBb);
        $sepSep = self::coerceOptionalCsvString($context, $separator, ',');
        $encSep = self::coerceOptionalCsvString($context, $enclosure, '"');
        $escSep = self::coerceOptionalCsvString($context, $escape, '\\');
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STR_GETCSV_HELPER),
            [$strippedSep, $sepSep, $encSep, $escSep]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        // Line-terminator-only rows: helper returns [] → synthesize [null] (#27069 / #10623).
        $htIsNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $num = $context->builder->select(
            $htIsNull,
            $sizeT->constInt(0, false),
            $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht)
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('str_getcsv_bridge_empty_null_row');
        $retBb = $fn->appendBasicBlock('str_getcsv_bridge_ret');
        $context->builder->branchIf($isEmpty, $emptyBb, $retBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::allocNullRowHashtable($context));

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    /** php-src empty / CRLF-only CSV row → one NULL field (#4922 / #10623). */
    private static function allocNullRowHashtable(Context $context): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $nullRow = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setNullAt'),
            $nullRow,
            $sizeT->constInt(0, false)
        );

        return $nullRow;
    }

    public static function coerceOptionalCsvStringForFgetcsv(Context $context, Value $arg, string $default): Value
    {
        return self::coerceOptionalCsvString($context, $arg, $default);
    }

    private static function coerceOptionalCsvString(Context $context, Value $arg, string $default): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        // Load before CFG split — constantStringFromString may temporarily move the builder;
        // never pass raw php_cstr ([N x i8]*) into __string__separate (#27069 Module verify).
        $defaultStr = $context->builder->load($context->constantStringFromString($default));

        $fn = BasicBlockHelper::parentFunction($context);
        $nullBb = $fn->appendBasicBlock('csv_opt_str_null');
        $checkBb = $fn->appendBasicBlock('csv_opt_str_check');
        $useBb = $fn->appendBasicBlock('csv_opt_str_use');
        $doneBb = $fn->appendBasicBlock('csv_opt_str_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $arg, $strPtr->constNull());
        $context->builder->branchIf($isNull, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($arg, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($empty, $nullBb, $useBb);

        $context->builder->positionAtEnd($useBb);
        $separated = $context->builder->call($context->lookupFunction('__string__separate'), $arg);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($defaultStr, $nullBb);
        $phi->addIncoming($separated, $useBb);

        return $phi;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27069');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#27069'
        );
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrGetcsv bridge (#9444)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
