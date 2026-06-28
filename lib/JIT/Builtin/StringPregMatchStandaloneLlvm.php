<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * LLVM preg_replace_callback slice for embed/standalone AOT (#5289, #9542, #12982).
 *
 * All other preg_* ABI routes through {@see PregMatchRuntime} + {@see PregJitHelper} PHP.
 * Uses libpcre2-8 via declared external functions (#5289, #6639).
 */
final class StringPregMatchStandaloneLlvm
{
    private const GLOBAL_LAST_ERROR = 'phpc_preg_last_error';

    private const PHPC_PREG_NO_ERROR = 0;

    private const PHPC_PREG_INTERNAL_ERROR = 1;

    private const PHPC_PREG_BACKTRACK_LIMIT_ERROR = 2;

    private const PHPC_PREG_RECURSION_LIMIT_ERROR = 3;

    private const PHPC_PREG_BAD_UTF8_ERROR = 4;

    private const PHPC_PREG_BAD_UTF8_OFFSET_ERROR = 5;

    private const PHPC_PREG_JIT_STACKLIMIT_ERROR = 6;

    private const PCRE2_CASELESS = 0x00000008;

    private const PCRE2_DOTALL = 0x00000020;

    private const PCRE2_DOLLAR_ENDONLY = 0x00000010;

    private const PCRE2_EXTENDED = 0x00000080;

    private const PCRE2_MULTILINE = 0x00000400;

    private const PCRE2_UNGREEDY = 0x00040000;

    private const PCRE2_UTF = 0x00080000;

    private const PCRE2_ANCHORED = 0x80000000;

    private const PCRE2_ERROR_NOMATCH = -1;

    private const PCRE2_ERROR_MATCHLIMIT = -8;

    private const PCRE2_ERROR_DEPTHLIMIT = -9;

    private const PCRE2_ERROR_BADDATA = -43;

    private const PCRE2_ERROR_JIT_STACKLIMIT = -45;

    private const PCRE2_ERROR_BADUTFOFFSET = -48;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_preg_last_error',
        '__compiler_preg_last_error_msg',
        '__compiler_preg_match',
        '__compiler_preg_match_ex',
        '__compiler_preg_match_all',
        '__compiler_preg_match_all_ex',
        '__compiler_preg_replace',
        '__compiler_preg_replace_callback',
        '__compiler_preg_split',
    ];

    /** @var Value|null */
    private static $lastErrorGlobal = null;

    private static int $blockSuffix = 0;

    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $i32p = $i32->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $voidPtr = $context->getTypeFromString('void*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $callbackFnTy = $context->context->functionType($valuePtr, false, $valuePtr);

        return match ($name) {
            '__phpc_preg_set_error' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i32)
            ),
            '__phpc_preg_is_valid_delimiter' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_pcre2_error_to_preg' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i32)
            ),
            '__phpc_preg_parse_php_pattern' => $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $i32,
                    false,
                    $i8p,
                    $sizeT,
                    $i8pp,
                    $sizeTp,
                    $i32p
                )
            ),
            '__phpc_preg_compile' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $strPtr, $i32p)
            ),
            '__phpc_preg_match_internal' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__phpc_preg_replace_internal' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
            ),
            '__compiler_preg_last_error' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false)
            ),
            '__compiler_preg_last_error_msg' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false)
            ),
            '__compiler_preg_match', '__compiler_preg_match_all' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__compiler_preg_match_ex', '__compiler_preg_match_all_ex' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $valuePtr, $i64, $i64)
            ),
            '__compiler_preg_replace' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
            ),
            '__compiler_preg_replace_callback' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $callbackFnTy->pointerType(0))
            ),
            '__compiler_preg_split' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64)
            ),
            default => throw new \LogicException('Unknown preg_match JIT helper: '.$name),
        };
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR)) {
            self::$lastErrorGlobal = $context->module->addGlobal($i32, self::GLOBAL_LAST_ERROR);
            self::$lastErrorGlobal->setInitializer($i32->constInt(0, false));
        } else {
            self::$lastErrorGlobal = $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR);
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $i8p, [$sizeT]],
                ['realloc', $i8p, [$i8p, $sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensurePcre2(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i32p = $i32->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                ['pcre2_compile_8', $i8p, [$i8p, $sizeT, $i32, $i32p, $sizeTp, $voidPtr]],
                ['pcre2_code_free_8', $voidTy, [$i8p]],
                ['pcre2_match_data_create_from_pattern_8', $i8p, [$i8p, $voidPtr]],
                ['pcre2_match_data_free_8', $voidTy, [$i8p]],
                ['pcre2_match_8', $i32, [$i8p, $i8p, $sizeT, $sizeT, $i32, $i8p, $voidPtr]],
                ['pcre2_get_ovector_pointer_8', $sizeTp, [$i8p]],
                ['pcre2_get_ovector_count_8', $i32, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');

        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringAt', $void, [$htPtr, $sizeT, $strPtr]],
                ['__hashtable__setLongAt', $void, [$htPtr, $sizeT, $i64]],
                ['__hashtable__setHashtableAt', $void, [$htPtr, $sizeT, $htPtr]],
                ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
                ['__value__readString', $strPtr, [$valuePtr]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
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

    private static function emitSetError(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->store($fn->getParam(0), self::$lastErrorGlobal);
        $context->builder->returnVoid();
    }

    private static function emitIsValidDelimiter(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $c = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i8->constInt(0, false);
        $backslash = $i8->constInt(92, false);

        $isNul = $context->builder->icmp(Builder::INT_EQ, $c, $zero);
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $c, $backslash);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(57, false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(90, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(122, false))
        );
        $invalid = $context->builder->or(
            $isNul,
            $context->builder->or($isSlash, $context->builder->or($isDigit, $context->builder->or($isUpper, $isLower)))
        );

        $context->builder->returnValue(
            $context->builder->select(
                $invalid,
                $i32->constInt(0, false),
                $i32->constInt(1, false)
            )
        );
    }

    private static function emitPcre2ErrorToPreg(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $code = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $result = $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false);

        $isZero = $context->builder->icmp(Builder::INT_EQ, $code, $i32->constInt(0, false));
        $zeroBb = $fn->appendBasicBlock('pe_zero');
        $checkUtf8Bb = $fn->appendBasicBlock('pe_check_utf8');
        $doneBb = $fn->appendBasicBlock('pe_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->branchIf($isZero, $zeroBb, $checkUtf8Bb);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->store($i32->constInt(self::PHPC_PREG_NO_ERROR, false), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkUtf8Bb);
        $inUtf8Range = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $code, $i32->constInt(-44, true)),
            $context->builder->icmp(Builder::INT_SLE, $code, $i32->constInt(-2, true))
        );
        $utf8Bb = $fn->appendBasicBlock('pe_utf8');
        $switchBb = $fn->appendBasicBlock('pe_switch');
        $context->builder->branchIf($inUtf8Range, $utf8Bb, $switchBb);

        $context->builder->positionAtEnd($utf8Bb);
        $context->builder->store($i32->constInt(self::PHPC_PREG_BAD_UTF8_ERROR, false), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($switchBb);
        $mapped = $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false);
        foreach (
            [
                self::PCRE2_ERROR_BADUTFOFFSET => self::PHPC_PREG_BAD_UTF8_OFFSET_ERROR,
                self::PCRE2_ERROR_BADDATA => self::PHPC_PREG_BAD_UTF8_OFFSET_ERROR,
                self::PCRE2_ERROR_MATCHLIMIT => self::PHPC_PREG_BACKTRACK_LIMIT_ERROR,
                self::PCRE2_ERROR_DEPTHLIMIT => self::PHPC_PREG_RECURSION_LIMIT_ERROR,
                self::PCRE2_ERROR_JIT_STACKLIMIT => self::PHPC_PREG_JIT_STACKLIMIT_ERROR,
            ] as $pcreCode => $pregCode
        ) {
            $mapped = $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $code, $i32->constInt($pcreCode, true)),
                $i32->constInt($pregCode, false),
                $mapped
            );
        }
        $context->builder->store($mapped, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($resultSlot));
    }

    private static function emitParsePhpPattern(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pp_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $patternLen = $fn->getParam(1);
        $regexOut = $fn->getParam(2);
        $regexLenOut = $fn->getParam(3);
        $optsOut = $fn->getParam(4);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);
        $zeroSize = $sizeT->constInt(0, false);
        $failBb = $fn->appendBasicBlock('pp_fail');
        $nullPattern = self::isNullI8Ptr($context, $pattern);
        $shortPattern = $context->builder->icmp(Builder::INT_ULT, $patternLen, $two);
        $preCheckBb = $fn->appendBasicBlock('pp_pre_check');
        $context->builder->branchIf(
            $context->builder->or($nullPattern, $shortPattern),
            $failBb,
            $preCheckBb
        );

        $context->builder->positionAtEnd($preCheckBb);
        $delimiter = $context->builder->load($pattern);
        $validDelim = $context->builder->call(
            $context->lookupFunction('__phpc_preg_is_valid_delimiter'),
            $delimiter
        );
        $delimOkBb = $fn->appendBasicBlock('pp_delim_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $validDelim, $i32->constInt(0, false)),
            $delimOkBb,
            $failBb
        );

        $context->builder->positionAtEnd($delimOkBb);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $end = $context->builder->inBoundsGEP($pattern, $patternLen);
        $context->builder->store($context->builder->inBoundsGEP($pattern, $one), $pSlot);

        $scanHead = $fn->appendBasicBlock('pp_scan_head');
        $scanBody = $fn->appendBasicBlock('pp_scan_body');
        $scanDone = $fn->appendBasicBlock('pp_scan_done');
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHead);
        $p = $context->builder->load($pSlot);
        $atEnd = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($p, $context->getTypeFromString('int64')),
            $context->builder->ptrToInt($end, $context->getTypeFromString('int64'))
        );
        $context->builder->branchIf($atEnd, $failBb, $scanBody);

        $context->builder->positionAtEnd($scanBody);
        $ch = $context->builder->load($p);
        $isEscape = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(92, false));
        $isDelim = $context->builder->icmp(Builder::INT_EQ, $ch, $delimiter);
        $escapeBb = $fn->appendBasicBlock('pp_escape');
        $foundBb = $fn->appendBasicBlock('pp_found');
        $advanceBb = $fn->appendBasicBlock('pp_advance');
        $checkDelimBb = $fn->appendBasicBlock('pp_check_delim');
        $context->builder->branchIf($isEscape, $escapeBb, $checkDelimBb);

        $context->builder->positionAtEnd($checkDelimBb);
        $context->builder->branchIf($isDelim, $foundBb, $advanceBb);

        $context->builder->positionAtEnd($escapeBb);
        $nextP = $context->builder->inBoundsGEP($p, $one);
        $escapeEnd = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($nextP, $context->getTypeFromString('int64')),
            $context->builder->ptrToInt($end, $context->getTypeFromString('int64'))
        );
        $escapeOkBb = $fn->appendBasicBlock('pp_escape_ok');
        $context->builder->branchIf($escapeEnd, $failBb, $escapeOkBb);
        $context->builder->positionAtEnd($escapeOkBb);
        $context->builder->store($context->builder->inBoundsGEP($p, $two), $pSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($advanceBb);
        $context->builder->store($context->builder->inBoundsGEP($p, $one), $pSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($foundBb);
        $p = $context->builder->load($pSlot);
        $regexStart = $context->builder->inBoundsGEP($pattern, $one);
        $regexLen = $context->builder->sub(
            $context->builder->ptrToInt($p, $context->getTypeFromString('int64')),
            $context->builder->ptrToInt($regexStart, $context->getTypeFromString('int64'))
        );
        $regexLenSizeT = $context->builder->truncOrBitCast($regexLen, $sizeT);
        $allocSize = $context->builder->add($regexLenSizeT, $one);
        $regexBuf = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $mallocFail = self::isNullI8Ptr($context, $regexBuf);
        $copyBb = $fn->appendBasicBlock('pp_copy');
        $context->builder->branchIf($mallocFail, $failBb, $copyBb);

        $context->builder->positionAtEnd($copyBb);
        $regexPtr = $context->builder->pointerCast($regexBuf, $i8p);
        $hasBody = $context->builder->icmp(Builder::INT_UGT, $regexLenSizeT, $zeroSize);
        $afterCopyBb = $fn->appendBasicBlock('pp_after_copy');
        $doCopyBb = $fn->appendBasicBlock('pp_do_copy');
        $context->builder->branchIf($hasBody, $doCopyBb, $afterCopyBb);

        $context->builder->positionAtEnd($doCopyBb);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($regexPtr),
            $context->bytePtr($regexStart),
            $regexLenSizeT
        );
        $context->builder->branch($afterCopyBb);

        $context->builder->positionAtEnd($afterCopyBb);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($regexPtr, $regexLenSizeT));
        $context->builder->store($regexPtr, $regexOut);
        $context->builder->store($regexLenSizeT, $regexLenOut);
        $context->builder->store($i32->constInt(0, false), $optsOut);

        $modP = $context->builder->inBoundsGEP($p, $one);
        $modSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($modP, $modSlot);
        $modHead = $fn->appendBasicBlock('pp_mod_head');
        $modBody = $fn->appendBasicBlock('pp_mod_body');
        $modDone = $fn->appendBasicBlock('pp_mod_done');
        $context->builder->branch($modHead);

        $context->builder->positionAtEnd($modHead);
        $mp = $context->builder->load($modSlot);
        $modEnd = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($mp, $context->getTypeFromString('int64')),
            $context->builder->ptrToInt($end, $context->getTypeFromString('int64'))
        );
        $context->builder->branchIf($modEnd, $modDone, $modBody);

        $context->builder->positionAtEnd($modBody);
        $modCh = $context->builder->load($mp);
        $opts = $context->builder->load($optsOut);
        $newOpts = self::applyPatternModifier($context, $modCh, $opts);
        $badMod = $context->builder->icmp(Builder::INT_EQ, $newOpts, $i32->constInt(-1, true));
        $modOkBb = $fn->appendBasicBlock('pp_mod_ok');
        $modBadBb = $fn->appendBasicBlock('pp_mod_bad');
        $context->builder->branchIf($badMod, $modBadBb, $modOkBb);

        $context->builder->positionAtEnd($modBadBb);
        $context->builder->call($context->lookupFunction('free'), $regexPtr);
        $context->builder->store($i8p->constNull(), $regexOut);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($modOkBb);
        $context->builder->store($newOpts, $optsOut);
        $context->builder->store($context->builder->inBoundsGEP($mp, $one), $modSlot);
        $context->builder->branch($modHead);

        $context->builder->positionAtEnd($modDone);
        $context->builder->returnValue($i32->constInt(1, false));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function applyPatternModifier(Context $context, Value $modCh, Value $opts): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $bad = $i32->constInt(-1, true);
        $result = $opts;

        foreach (
            [
                'i' => self::PCRE2_CASELESS,
                'm' => self::PCRE2_MULTILINE,
                's' => self::PCRE2_DOTALL,
                'x' => self::PCRE2_EXTENDED,
                'A' => self::PCRE2_ANCHORED,
                'D' => self::PCRE2_DOLLAR_ENDONLY,
                'U' => self::PCRE2_UNGREEDY,
                'u' => self::PCRE2_UTF,
            ] as $letter => $flag
        ) {
            $matches = $context->builder->icmp(
                Builder::INT_EQ,
                $modCh,
                $i8->constInt(\ord($letter), false)
            );
            $result = $context->builder->select(
                $matches,
                $context->builder->or($result, $i32->constInt($flag, false)),
                $result
            );
        }

        $known = $context->getTypeFromString('int1')->constInt(0, false);
        foreach (['i', 'm', 's', 'x', 'A', 'D', 'U', 'u'] as $letter) {
            $known = $context->builder->or(
                $known,
                $context->builder->icmp(Builder::INT_EQ, $modCh, $i8->constInt(\ord($letter), false))
            );
        }

        return $context->builder->select($known, $result, $bad);
    }

    private static function emitCompile(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pc_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $pregErrorOut = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $nullCode = $i8p->constNull();

        $context->builder->store($i32->constInt(self::PHPC_PREG_NO_ERROR, false), $pregErrorOut);

        $regexSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $regexLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $optsSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $pat = self::stringData($context, $pattern);
        $patLen = $context->builder->truncOrBitCast(self::stringLen($context, $pattern), $sizeT);
        $parsed = $context->builder->call(
            $context->lookupFunction('__phpc_preg_parse_php_pattern'),
            $pat,
            $patLen,
            $regexSlot,
            $regexLenSlot,
            $optsSlot
        );
        $parseFailBb = $fn->appendBasicBlock('pc_parse_fail');
        $compileBb = $fn->appendBasicBlock('pc_compile');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $parsed, $i32->constInt(0, false)),
            $parseFailBb,
            $compileBb
        );

        $context->builder->positionAtEnd($parseFailBb);
        $context->builder->store($i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false), $pregErrorOut);
        $context->builder->returnValue($nullCode);

        $context->builder->positionAtEnd($compileBb);
        $errorCodeSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $errorOffsetSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $regex = $context->builder->load($regexSlot);
        $regexLen = $context->builder->load($regexLenSlot);
        $opts = $context->builder->load($optsSlot);
        $code = $context->builder->call(
            $context->lookupFunction('pcre2_compile_8'),
            $regex,
            $regexLen,
            $opts,
            $errorCodeSlot,
            $errorOffsetSlot,
            $voidPtr->constNull()
        );
        $context->builder->call($context->lookupFunction('free'), $regex);
        $codeNull = self::isNullI8Ptr($context, $code);
        $okBb = $fn->appendBasicBlock('pc_ok');
        $compileFailBb = $fn->appendBasicBlock('pc_compile_fail');
        $context->builder->branchIf($codeNull, $compileFailBb, $okBb);

        $context->builder->positionAtEnd($compileFailBb);
        $mapped = $context->builder->call(
            $context->lookupFunction('__phpc_pcre2_error_to_preg'),
            $context->builder->load($errorCodeSlot)
        );
        $context->builder->store($mapped, $pregErrorOut);
        $context->builder->returnValue($nullCode);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($context->builder->pointerCast($code, $i8p));
    }

    private static function appendToBuffer(
        Context $context,
        LlvmFunction $fn,
        Value $bufSlot,
        Value $bufLenSlot,
        Value $bufCapSlot,
        Value $src,
        Value $len
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $i32 = $context->getTypeFromString('int32');

        $bufLen = $context->builder->load($bufLenSlot);
        $need = $context->builder->add($bufLen, $len);
        $needPlusOne = $context->builder->add($need, $sizeT->constInt(1, false));
        $bufCap = $context->builder->load($bufCapSlot);
        $fits = $context->builder->icmp(Builder::INT_ULT, $needPlusOne, $bufCap);
        $appendBb = $fn->appendBasicBlock('pr_append_'.(++self::$blockSuffix));
        $growBb = $fn->appendBasicBlock('pr_grow_'.self::$blockSuffix);
        $context->builder->branchIf($fits, $appendBb, $growBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->add($need, $sizeT->constInt(64, false));
        $buf = $context->builder->load($bufSlot);
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($buf),
            $newCap
        );
        $growFailBb = $fn->appendBasicBlock('pr_grow_fail_'.self::$blockSuffix);
        $growOkBb = $fn->appendBasicBlock('pr_grow_ok_'.self::$blockSuffix);
        $context->builder->branchIf(
            self::isNullI8Ptr($context, $grown),
            $growFailBb,
            $growOkBb
        );
        $context->builder->positionAtEnd($growFailBb);
        $hasBuf = $context->builder->not(self::isNullI8Ptr($context, $buf));
        $freeOldBb = $fn->appendBasicBlock('pr_free_old_'.self::$blockSuffix);
        $growErrBb = $fn->appendBasicBlock('pr_grow_err_'.self::$blockSuffix);
        $context->builder->branchIf($hasBuf, $freeOldBb, $growErrBb);
        $context->builder->positionAtEnd($freeOldBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($growErrBb);
        $context->builder->positionAtEnd($growErrBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());

        $context->builder->positionAtEnd($growOkBb);
        $buf = $context->builder->pointerCast($grown, $i8p);
        $context->builder->store($buf, $bufSlot);
        $context->builder->store($newCap, $bufCapSlot);
        $context->builder->branch($appendBb);

        $context->builder->positionAtEnd($appendBb);
        $buf = $context->builder->load($bufSlot);
        $bufLen = $context->builder->load($bufLenSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->gep($buf, $bufLen),
            $context->bytePtr($src),
            $len
        );
        $context->builder->store($context->builder->add($bufLen, $len), $bufLenSlot);
    }

    private static function emitReplaceCallback(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('crc_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $subject = $fn->getParam(1);
        $callback = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $nullStr = $strPtr->constNull();
        $valueMap = $context->structFieldMap['__value__'];

        $pregErrorSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $code = $context->builder->call(
            $context->lookupFunction('__phpc_preg_compile'),
            $pattern,
            $pregErrorSlot
        );
        $codeNull = self::isNullI8Ptr($context, $code);
        $compileFailBb = $fn->appendBasicBlock('crc_compile_fail');
        $createMdBb = $fn->appendBasicBlock('crc_create_md');
        $context->builder->branchIf($codeNull, $compileFailBb, $createMdBb);

        $context->builder->positionAtEnd($compileFailBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $context->builder->load($pregErrorSlot)
        );
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($createMdBb);
        $matchData = $context->builder->call(
            $context->lookupFunction('pcre2_match_data_create_from_pattern_8'),
            $code,
            $voidPtr->constNull()
        );
        $mdNull = self::isNullI8Ptr($context, $matchData);
        $initLoopBb = $fn->appendBasicBlock('crc_init_loop');
        $mdFailBb = $fn->appendBasicBlock('crc_md_fail');
        $context->builder->branchIf($mdNull, $mdFailBb, $initLoopBb);

        $context->builder->positionAtEnd($mdFailBb);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($initLoopBb);
        $subj = self::stringData($context, $subject);
        $subjLen = $context->builder->truncOrBitCast(self::stringLen($context, $subject), $sizeT);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $bufLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $bufCapSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $offsetSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($i8p->constNull(), $bufSlot);
        $context->builder->store($sizeT->constInt(0, false), $bufLenSlot);
        $context->builder->store($sizeT->constInt(0, false), $bufCapSlot);
        $context->builder->store($sizeT->constInt(0, false), $offsetSlot);

        $loopHead = $fn->appendBasicBlock('crc_loop_head');
        $loopBody = $fn->appendBasicBlock('crc_loop_body');
        $loopDone = $fn->appendBasicBlock('crc_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_ULT, $offset, $subjLen);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $rc = $context->builder->call(
            $context->lookupFunction('pcre2_match_8'),
            $code,
            $subj,
            $subjLen,
            $offset,
            $i32->constInt(0, false),
            $matchData,
            $voidPtr->constNull()
        );
        $isNomatch = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::PCRE2_ERROR_NOMATCH, true));
        $isError = $context->builder->icmp(Builder::INT_SLT, $rc, $i32->constInt(0, false));
        $tailBb = $fn->appendBasicBlock('crc_tail');
        $matchErrBb = $fn->appendBasicBlock('crc_match_err');
        $replaceBb = $fn->appendBasicBlock('crc_replace');
        $checkErrBb = $fn->appendBasicBlock('crc_check_err');
        $context->builder->branchIf($isNomatch, $tailBb, $checkErrBb);

        $context->builder->positionAtEnd($checkErrBb);
        $context->builder->branchIf($isError, $matchErrBb, $replaceBb);

        $context->builder->positionAtEnd($tailBb);
        $tailLen = $context->builder->sub($subjLen, $offset);
        $hasTail = $context->builder->icmp(Builder::INT_UGT, $tailLen, $sizeT->constInt(0, false));
        $afterTailBb = $fn->appendBasicBlock('crc_after_tail');
        $copyTailBb = $fn->appendBasicBlock('crc_copy_tail');
        $context->builder->branchIf($hasTail, $copyTailBb, $afterTailBb);
        $context->builder->positionAtEnd($copyTailBb);
        self::appendToBuffer($context, $fn, $bufSlot, $bufLenSlot, $bufCapSlot, $context->builder->gep($subj, $offset), $tailLen);
        $context->builder->branch($afterTailBb);
        $context->builder->positionAtEnd($afterTailBb);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($matchErrBb);
        $buf = $context->builder->load($bufSlot);
        $hasBuf = $context->builder->not(self::isNullI8Ptr($context, $buf));
        $cleanupBb = $fn->appendBasicBlock('crc_cleanup_err');
        $freeBufBb = $fn->appendBasicBlock('crc_free_buf');
        $context->builder->branchIf($hasBuf, $freeBufBb, $cleanupBb);
        $context->builder->positionAtEnd($freeBufBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($cleanupBb);
        $context->builder->positionAtEnd($cleanupBb);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $mapped = $context->builder->call($context->lookupFunction('__phpc_pcre2_error_to_preg'), $rc);
        $context->builder->call($context->lookupFunction('__phpc_preg_set_error'), $mapped);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($replaceBb);
        $ovector = $context->builder->call(
            $context->lookupFunction('pcre2_get_ovector_pointer_8'),
            $matchData
        );
        $start = $context->builder->load($ovector);
        $end = $context->builder->load($context->builder->inBoundsGEP($ovector, $sizeT->constInt(1, false)));
        $prefixLen = $context->builder->sub($start, $offset);
        $hasPrefix = $context->builder->icmp(Builder::INT_UGT, $prefixLen, $sizeT->constInt(0, false));
        $afterPrefixBb = $fn->appendBasicBlock('crc_after_prefix');
        $copyPrefixBb = $fn->appendBasicBlock('crc_copy_prefix');
        $context->builder->branchIf($hasPrefix, $copyPrefixBb, $afterPrefixBb);
        $context->builder->positionAtEnd($copyPrefixBb);
        self::appendToBuffer(
            $context,
            $fn,
            $bufSlot,
            $bufLenSlot,
            $bufCapSlot,
            $context->builder->gep($subj, $offset),
            $prefixLen
        );
        $context->builder->branch($afterPrefixBb);
        $context->builder->positionAtEnd($afterPrefixBb);

        $matchesHt = self::buildMatchHashtable($context, $fn, $matchData, $subject);
        $argSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $context->builder->store(
            $i8->constInt(\PHPCompiler\JIT\Variable::TYPE_NULL, false),
            $context->builder->structGep($argSlot, $valueMap['type'])
        );
        $argPtr = $context->builder->pointerCast($argSlot, $valuePtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $argPtr,
            $matchesHt
        );
        $callbackFnTy = $callback->typeOf()->getElementType();
        $cbResult = self::emitIndirectCall($context, $callbackFnTy, $callback, $argPtr);
        $replStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $cbResult
        );
        $replLen = $context->builder->truncOrBitCast(self::stringLen($context, $replStr), $sizeT);
        $hasRepl = $context->builder->icmp(Builder::INT_UGT, $replLen, $sizeT->constInt(0, false));
        $afterReplBb = $fn->appendBasicBlock('crc_after_repl');
        $copyReplBb = $fn->appendBasicBlock('crc_copy_repl');
        $context->builder->branchIf($hasRepl, $copyReplBb, $afterReplBb);
        $context->builder->positionAtEnd($copyReplBb);
        self::appendToBuffer(
            $context,
            $fn,
            $bufSlot,
            $bufLenSlot,
            $bufCapSlot,
            self::stringData($context, $replStr),
            $replLen
        );
        $context->builder->branch($afterReplBb);
        $context->builder->positionAtEnd($afterReplBb);

        $matchLen = $context->builder->sub($end, $start);
        $nextOffset = $context->builder->add($start, $matchLen);
        $stalled = $context->builder->icmp(Builder::INT_ULE, $nextOffset, $offset);
        $stalledBb = $fn->appendBasicBlock('crc_stalled');
        $advanceBb = $fn->appendBasicBlock('crc_advance');
        $context->builder->branchIf($stalled, $stalledBb, $advanceBb);
        $context->builder->positionAtEnd($stalledBb);
        $buf = $context->builder->load($bufSlot);
        $hasBuf = $context->builder->not(self::isNullI8Ptr($context, $buf));
        $stallCleanupBb = $fn->appendBasicBlock('crc_stall_cleanup');
        $stallFreeBb = $fn->appendBasicBlock('crc_stall_free');
        $context->builder->branchIf($hasBuf, $stallFreeBb, $stallCleanupBb);
        $context->builder->positionAtEnd($stallFreeBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($stallCleanupBb);
        $context->builder->positionAtEnd($stallCleanupBb);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($advanceBb);
        $context->builder->store($nextOffset, $offsetSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $buf = $context->builder->load($bufSlot);
        $bufLen = $context->builder->load($bufLenSlot);
        $bufNull = self::isNullI8Ptr($context, $buf);
        $allocEmptyBb = $fn->appendBasicBlock('crc_alloc_empty');
        $buildBb = $fn->appendBasicBlock('crc_build');
        $context->builder->branchIf($bufNull, $allocEmptyBb, $buildBb);

        $context->builder->positionAtEnd($allocEmptyBb);
        $emptyBuf = $context->builder->call($context->lookupFunction('malloc'), $sizeT->constInt(1, false));
        $emptyFailBb = $fn->appendBasicBlock('crc_empty_fail');
        $emptyOkBb = $fn->appendBasicBlock('crc_empty_ok');
        $context->builder->branchIf(
            self::isNullI8Ptr($context, $emptyBuf),
            $emptyFailBb,
            $emptyOkBb
        );
        $context->builder->positionAtEnd($emptyFailBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($emptyOkBb);
        $buf = $context->builder->pointerCast($emptyBuf, $i8p);
        $context->builder->store($buf, $bufSlot);
        $bufLen = $sizeT->constInt(0, false);
        $context->builder->store($bufLen, $bufLenSlot);
        $context->builder->branch($buildBb);

        $context->builder->positionAtEnd($buildBb);
        $buf = $context->builder->load($bufSlot);
        $bufLen = $context->builder->load($bufLenSlot);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($buf, $bufLen)
        );
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($bufLen, $i64),
            $buf
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_NO_ERROR, false)
        );
        $context->builder->returnValue($result);
    }

    private const PREG_SPLIT_NO_EMPTY = 1;

    private const PREG_SPLIT_DELIM_CAPTURE = 2;

    private const PREG_SPLIT_OFFSET_CAPTURE = 4;

    private static function sliceSubjectToString(
        Context $context,
        LlvmFunction $fn,
        Value $subject,
        Value $offset,
        Value $len
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('slice_empty_'.(++self::$blockSuffix));
        $copyBb = $fn->appendBasicBlock('slice_copy_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('slice_done_'.self::$blockSuffix);
        $resume = $context->builder->getInsertBlock();
        $context->builder->branchIf($isEmpty, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyOkBb = $fn->appendBasicBlock('slice_empty_ok_'.self::$blockSuffix);
        $emptyBuf = $context->builder->call($context->lookupFunction('malloc'), $sizeT->constInt(1, false));
        $context->builder->branch($emptyOkBb);
        $context->builder->positionAtEnd($emptyOkBb);
        $buf = $context->builder->pointerCast($emptyBuf, $i8p);
        $context->builder->store($i8->constInt(0, false), $buf);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $buf
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        $copyOkBb = $fn->appendBasicBlock('slice_copy_ok_'.self::$blockSuffix);
        $need = $context->builder->add($len, $sizeT->constInt(1, false));
        $buf = $context->builder->call($context->lookupFunction('malloc'), $need);
        $context->builder->branch($copyOkBb);
        $context->builder->positionAtEnd($copyOkBb);
        $buf = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy(
            $buf,
            $context->builder->inBoundsGEP(self::stringData($context, $subject), $offset),
            $len,
            false
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $len));
        $sliceStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $buf
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($strPtr);
        $result->addIncoming($emptyStr, $emptyOkBb);
        $result->addIncoming($sliceStr, $copyOkBb);
        $continueBb = $fn->appendBasicBlock('slice_continue_'.self::$blockSuffix);
        $context->builder->branch($continueBb);
        $context->builder->positionAtEnd($continueBb);

        return $result;
    }

    private static function buildMatchHashtable(
        Context $context,
        LlvmFunction $fn,
        Value $matchData,
        Value $subject
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $count = $context->builder->call(
            $context->lookupFunction('pcre2_get_ovector_count_8'),
            $matchData
        );
        $ovector = $context->builder->call(
            $context->lookupFunction('pcre2_get_ovector_pointer_8'),
            $matchData
        );

        $idxSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $idxSlot);
        $loopHead = $fn->appendBasicBlock('bm_loop_head_'.(++self::$blockSuffix));
        $loopBody = $fn->appendBasicBlock('bm_loop_body_'.self::$blockSuffix);
        $loopDone = $fn->appendBasicBlock('bm_loop_done_'.self::$blockSuffix);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_SLT, $idx, $count);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $idx64 = $context->builder->sext($idx, $i64);
        $pairBase = $context->builder->mul($idx64, $i64->constInt(2, false));
        $start = $context->builder->load(
            $context->builder->inBoundsGEP($ovector, $context->builder->truncOrBitCast($pairBase, $sizeT))
        );
        $end = $context->builder->load(
            $context->builder->inBoundsGEP(
                $ovector,
                $context->builder->truncOrBitCast($context->builder->add($pairBase, $i64->constInt(1, false)), $sizeT)
            )
        );
        $valid = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $start, $sizeT->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SGE, $end, $sizeT->constInt(0, false))
        );
        $matchedBb = $fn->appendBasicBlock('bm_matched_'.self::$blockSuffix);
        $emptyBb = $fn->appendBasicBlock('bm_empty_'.self::$blockSuffix);
        $afterBb = $fn->appendBasicBlock('bm_after_'.self::$blockSuffix);
        $context->builder->branchIf($valid, $matchedBb, $emptyBb);

        $context->builder->positionAtEnd($matchedBb);
        $pieceLen = $context->builder->sub($end, $start);
        $pieceStr = self::sliceSubjectToString($context, $fn, $subject, $start, $pieceLen);
        $pieceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyPiece = self::sliceSubjectToString(
            $context,
            $fn,
            $subject,
            $sizeT->constInt(0, false),
            $sizeT->constInt(0, false)
        );
        $emptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        $piecePhi = $context->builder->phi($pieceStr->typeOf());
        $piecePhi->addIncoming($pieceStr, $pieceEnd);
        $piecePhi->addIncoming($emptyPiece, $emptyEnd);
        $index = $context->builder->truncOrBitCast($idx64, $sizeT);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $index,
            $piecePhi
        );
        $context->builder->store($context->builder->add($idx, $i32->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

        return $ht;
    }

    private static function isNullI8Ptr(Context $context, Value $ptr): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->ptrToInt($context->builder->pointerCast($ptr, $i8p), $i64),
            $i64->constInt(0, false)
        );
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for preg callback indirect call');
        }
        $valueWrapper = $b->llvm->lib->makeArray(
            LLVMValueRef_ptr::class,
            array_map(static fn (Value $value) => $value->value, $args)
        );

        return $b->llvm->factory->value(
            $context->context,
            $b->llvm->lib->LLVMBuildCall2(
                $b->builder,
                $fnTy->type,
                $fnPtr->value,
                $valueWrapper,
                \count($args),
                ''
            )
        );
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    /** Embed JIT: preg_replace_callback LLVM loop when PHP bridges cannot host the callback ABI (#9542). */
    public static function implementReplaceCallbackOnly(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::$blockSuffix = 0;
        self::ensureGlobals($context);
        PregExpandRuntime::ensureLinked($context);
        PregEmptyPatternReplaceRuntime::ensureLinked($context);
        self::ensureLibc($context);
        self::ensurePcre2($context);
        self::ensureRuntimeHelpers($context);
        self::implementIfMissing($context, '__phpc_preg_set_error', self::emitSetError(...));
        self::implementIfMissing($context, '__phpc_preg_is_valid_delimiter', self::emitIsValidDelimiter(...));
        self::implementIfMissing($context, '__phpc_pcre2_error_to_preg', self::emitPcre2ErrorToPreg(...));
        self::implementIfMissing($context, '__phpc_preg_parse_php_pattern', self::emitParsePhpPattern(...));
        self::implementIfMissing($context, '__phpc_preg_compile', self::emitCompile(...));
        self::implementIfMissing($context, '__compiler_preg_replace_callback', self::emitReplaceCallback(...));
        $fn = $context->module->getNamedFunction('__compiler_preg_replace_callback');
        if (null !== $fn) {
            $context->registerFunction('__compiler_preg_replace_callback', $fn);
        }
        self::restoreInsertBlock($context, $restore);
    }
}
