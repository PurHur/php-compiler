<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM preg_* runtime (mirrors lib/AOT/runtime/preg_match.c, issue #5289).
 *
 * Uses libpcre2-8 via declared external functions; preg_replace_callback and
 * preg_split are stubs (BAD_REGEX / NULL) matching the C spine.
 */
final class StringPregMatchJit
{
    private const GLOBAL_LAST_ERROR = 'phpc_preg_last_error';

    private const PHPC_PREG_NO_ERROR = 0;

    private const PHPC_PREG_INTERNAL_ERROR = 1;

    private const PHPC_PREG_BACKTRACK_LIMIT_ERROR = 2;

    private const PHPC_PREG_RECURSION_LIMIT_ERROR = 3;

    private const PHPC_PREG_BAD_UTF8_ERROR = 4;

    private const PHPC_PREG_BAD_UTF8_OFFSET_ERROR = 5;

    private const PHPC_PREG_BAD_REGEX = 6;

    private const PHPC_PREG_JIT_STACKLIMIT_ERROR = 7;

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
        '__compiler_preg_match_all',
        '__compiler_preg_replace',
        '__compiler_preg_replace_callback',
        '__compiler_preg_split',
    ];

    /** @var Value|null */
    private static $lastErrorGlobal = null;

    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_preg_match');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensurePcre2($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__phpc_preg_set_error', self::emitSetError(...));
        self::implementIfMissing($context, '__phpc_preg_is_valid_delimiter', self::emitIsValidDelimiter(...));
        self::implementIfMissing($context, '__phpc_pcre2_error_to_preg', self::emitPcre2ErrorToPreg(...));
        self::implementIfMissing($context, '__phpc_preg_parse_php_pattern', self::emitParsePhpPattern(...));
        self::implementIfMissing($context, '__phpc_preg_compile', self::emitCompile(...));
        self::implementIfMissing($context, '__phpc_preg_match_internal', self::emitMatchInternal(...));
        self::implementIfMissing($context, '__phpc_preg_replace_internal', self::emitReplaceInternal(...));

        self::implementIfMissing($context, '__compiler_preg_last_error', self::emitLastError(...));
        self::implementIfMissing($context, '__compiler_preg_last_error_msg', self::emitLastErrorMsg(...));
        self::implementIfMissing($context, '__compiler_preg_match', self::emitMatch(...));
        self::implementIfMissing($context, '__compiler_preg_match_all', self::emitMatchAll(...));
        self::implementIfMissing($context, '__compiler_preg_replace', self::emitReplace(...));
        self::implementIfMissing($context, '__compiler_preg_replace_callback', self::emitReplaceCallbackStub(...));
        self::implementIfMissing($context, '__compiler_preg_split', self::emitSplitStub(...));

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
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
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
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
            '__compiler_preg_replace' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
            ),
            '__compiler_preg_replace_callback' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $voidPtr, $strPtr)
            ),
            '__compiler_preg_split' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $strPtr)
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
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['realloc', $voidPtr, [$voidPtr, $sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
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
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
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
        $result = $i32->constInt(self::PHPC_PREG_BAD_REGEX, false);

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
        $mapped = $i32->constInt(self::PHPC_PREG_BAD_REGEX, false);
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
        $nullPtr = $i8p->constNull();

        $failBb = $fn->appendBasicBlock('pp_fail');
        $nullPattern = $context->builder->icmp(Builder::INT_EQ, $pattern, $nullPtr);
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
        $mallocFail = $context->builder->icmp(Builder::INT_EQ, $regexBuf, $voidPtr->constNull());
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
            $context->builder->pointerCast($regexPtr, $voidPtr),
            $context->builder->pointerCast($regexStart, $voidPtr),
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
        $context->builder->store($nullPtr, $regexOut);
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
        $context->builder->store($i32->constInt(self::PHPC_PREG_BAD_REGEX, false), $pregErrorOut);
        $context->builder->returnValue($nullCode);

        $context->builder->positionAtEnd($compileBb);
        $errorCodeSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $errorOffsetSlot = BasicBlockHelper->entryAlloca($context, $sizeT);
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
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $voidPtr->constNull());
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

    private static function emitMatchInternal(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pm_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $subject = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $negOneI64 = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);

        $pregErrorSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $code = $context->builder->call(
            $context->lookupFunction('__phpc_preg_compile'),
            $pattern,
            $pregErrorSlot
        );
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $i8p->constNull());
        $compileFailBb = $fn->appendBasicBlock('pm_compile_fail');
        $createMdBb = $fn->appendBasicBlock('pm_create_md');
        $context->builder->branchIf($codeNull, $compileFailBb, $createMdBb);

        $context->builder->positionAtEnd($compileFailBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $context->builder->load($pregErrorSlot)
        );
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($createMdBb);
        $matchData = $context->builder->call(
            $context->lookupFunction('pcre2_match_data_create_from_pattern_8'),
            $code,
            $voidPtr->constNull()
        );
        $mdNull = $context->builder->icmp(Builder::INT_EQ, $matchData, $voidPtr->constNull());
        $matchBb = $fn->appendBasicBlock('pm_match');
        $mdFailBb = $fn->appendBasicBlock('pm_md_fail');
        $context->builder->branchIf($mdNull, $mdFailBb, $matchBb);

        $context->builder->positionAtEnd($mdFailBb);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($matchBb);
        $subj = self::stringData($context, $subject);
        $subjLen = $context->builder->truncOrBitCast(self::stringLen($context, $subject), $sizeT);
        $rc = $context->builder->call(
            $context->lookupFunction('pcre2_match_8'),
            $code,
            $subj,
            $subjLen,
            $sizeT->constInt(0, false),
            $i32->constInt(0, false),
            $matchData,
            $voidPtr->constNull()
        );
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);

        $isNomatch = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::PCRE2_ERROR_NOMATCH, true));
        $isError = $context->builder->icmp(Builder::INT_SLT, $rc, $i32->constInt(0, false));
        $nomatchBb = $fn->appendBasicBlock('pm_nomatch');
        $errorBb = $fn->appendBasicBlock('pm_error');
        $successBb = $fn->appendBasicBlock('pm_success');
        $doneBb = $fn->appendBasicBlock('pm_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $checkErrBb = $fn->appendBasicBlock('pm_check_err');
        $context->builder->branchIf($isNomatch, $nomatchBb, $checkErrBb);

        $context->builder->positionAtEnd($checkErrBb);
        $context->builder->branchIf($isError, $errorBb, $successBb);

        $context->builder->positionAtEnd($nomatchBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_NO_ERROR, false)
        );
        $context->builder->store($zeroI64, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($errorBb);
        $mapped = $context->builder->call($context->lookupFunction('__phpc_pcre2_error_to_preg'), $rc);
        $context->builder->call($context->lookupFunction('__phpc_preg_set_error'), $mapped);
        $context->builder->store($negOneI64, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($successBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_NO_ERROR, false)
        );
        $hasMatch = $context->builder->icmp(Builder::INT_SGT, $rc, $i32->constInt(0, false));
        $context->builder->store(
            $context->builder->select($hasMatch, $oneI64, $zeroI64),
            $resultSlot
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($resultSlot));
    }

    private static function emitReplaceInternal(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pr_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $replacement = $fn->getParam(1);
        $subject = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $pregErrorSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $code = $context->builder->call(
            $context->lookupFunction('__phpc_preg_compile'),
            $pattern,
            $pregErrorSlot
        );
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $i8p->constNull());
        $compileFailBb = $fn->appendBasicBlock('pr_compile_fail');
        $createMdBb = $fn->appendBasicBlock('pr_create_md');
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
        $mdNull = $context->builder->icmp(Builder::INT_EQ, $matchData, $voidPtr->constNull());
        $initLoopBb = $fn->appendBasicBlock('pr_init_loop');
        $mdFailBb = $fn->appendBasicBlock('pr_md_fail');
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
        $repl = self::stringData($context, $replacement);
        $replLen = $context->builder->truncOrBitCast(self::stringLen($context, $replacement), $sizeT);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $bufLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $bufCapSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $offsetSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($i8p->constNull(), $bufSlot);
        $context->builder->store($sizeT->constInt(0, false), $bufLenSlot);
        $context->builder->store($sizeT->constInt(0, false), $bufCapSlot);
        $context->builder->store($sizeT->constInt(0, false), $offsetSlot);

        $loopHead = $fn->appendBasicBlock('pr_loop_head');
        $loopBody = $fn->appendBasicBlock('pr_loop_body');
        $loopDone = $fn->appendBasicBlock('pr_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_ULE, $offset, $subjLen);
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
        $tailBb = $fn->appendBasicBlock('pr_tail');
        $matchErrBb = $fn->appendBasicBlock('pr_match_err');
        $replaceBb = $fn->appendBasicBlock('pr_replace');
        $checkErrBb = $fn->appendBasicBlock('pr_check_err');
        $context->builder->branchIf($isNomatch, $tailBb, $checkErrBb);

        $context->builder->positionAtEnd($checkErrBb);
        $context->builder->branchIf($isError, $matchErrBb, $replaceBb);

        $context->builder->positionAtEnd($tailBb);
        $tailLen = $context->builder->sub($subjLen, $offset);
        $hasTail = $context->builder->icmp(Builder::INT_UGT, $tailLen, $sizeT->constInt(0, false));
        $afterTailBb = $fn->appendBasicBlock('pr_after_tail');
        $copyTailBb = $fn->appendBasicBlock('pr_copy_tail');
        $context->builder->branchIf($hasTail, $copyTailBb, $afterTailBb);
        $context->builder->positionAtEnd($copyTailBb);
        self::appendToBuffer(
            $context,
            $fn,
            $bufSlot,
            $bufLenSlot,
            $bufCapSlot,
            $context->builder->gep($subj, $offset),
            $tailLen
        );
        $context->builder->branch($afterTailBb);
        $context->builder->positionAtEnd($afterTailBb);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($matchErrBb);
        $buf = $context->builder->load($bufSlot);
        $hasBuf = $context->builder->icmp(Builder::INT_NE, $buf, $i8p->constNull());
        $cleanupBb = $fn->appendBasicBlock('pr_cleanup_err');
        $freeBufBb = $fn->appendBasicBlock('pr_free_buf');
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
        $afterPrefixBb = $fn->appendBasicBlock('pr_after_prefix');
        $copyPrefixBb = $fn->appendBasicBlock('pr_copy_prefix');
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
        $hasRepl = $context->builder->icmp(Builder::INT_UGT, $replLen, $sizeT->constInt(0, false));
        $afterReplBb = $fn->appendBasicBlock('pr_after_repl');
        $copyReplBb = $fn->appendBasicBlock('pr_copy_repl');
        $context->builder->branchIf($hasRepl, $copyReplBb, $afterReplBb);
        $context->builder->positionAtEnd($copyReplBb);
        self::appendToBuffer($context, $fn, $bufSlot, $bufLenSlot, $bufCapSlot, $repl, $replLen);
        $context->builder->branch($afterReplBb);
        $context->builder->positionAtEnd($afterReplBb);
        $samePos = $context->builder->icmp(Builder::INT_EQ, $end, $start);
        $nextOffset = $context->builder->select(
            $samePos,
            $context->builder->add($end, $sizeT->constInt(1, false)),
            $end
        );
        $context->builder->store($nextOffset, $offsetSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $buf = $context->builder->load($bufSlot);
        $bufLen = $context->builder->load($bufLenSlot);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $allocEmptyBb = $fn->appendBasicBlock('pr_alloc_empty');
        $buildBb = $fn->appendBasicBlock('pr_build');
        $context->builder->branchIf($bufNull, $allocEmptyBb, $buildBb);

        $context->builder->positionAtEnd($allocEmptyBb);
        $emptyBuf = $context->builder->call($context->lookupFunction('malloc'), $sizeT->constInt(1, false));
        $emptyFailBb = $fn->appendBasicBlock('pr_empty_fail');
        $emptyOkBb = $fn->appendBasicBlock('pr_empty_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $emptyBuf, $voidPtr->constNull()),
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
            $context->getTypeFromString('int8')->constInt(0, false),
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
            $context->builder->pointerCast($buf, $voidPtr),
            $newCap
        );
        $growFailBb = $fn->appendBasicBlock('pr_grow_fail_'.self::$blockSuffix);
        $growOkBb = $fn->appendBasicBlock('pr_grow_ok_'.self::$blockSuffix);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $grown, $voidPtr->constNull()),
            $growFailBb,
            $growOkBb
        );
        $context->builder->positionAtEnd($growFailBb);
        $hasBuf = $context->builder->icmp(Builder::INT_NE, $buf, $i8p->constNull());
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
            $context->builder->pointerCast($context->builder->gep($buf, $bufLen), $voidPtr),
            $context->builder->pointerCast($src, $voidPtr),
            $len
        );
        $context->builder->store($context->builder->add($bufLen, $len), $bufLenSlot);
    }

    private static function emitLastError(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ple_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $err = $context->builder->load(self::$lastErrorGlobal);
        $context->builder->returnValue($context->builder->sext($err, $i64));
    }

    private static function emitLastErrorMsg(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('plem_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $code = $context->builder->load(self::$lastErrorGlobal);
        $msg = self::pregErrorMessageCstr($context, $code);
        $len = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->zExt($len, $i64),
                $msg
            )
        );
    }

    private static function pregErrorMessageCstr(Context $context, Value $code): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $default = self::literalCstr($context, 'Unknown error');
        $result = $default;

        foreach (
            [
                self::PHPC_PREG_NO_ERROR => 'No error',
                self::PHPC_PREG_INTERNAL_ERROR => 'Internal error',
                self::PHPC_PREG_BAD_UTF8_ERROR => 'Malformed UTF-8 characters, possibly incorrectly encoded',
                self::PHPC_PREG_BAD_UTF8_OFFSET_ERROR => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
                self::PHPC_PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted',
                self::PHPC_PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
                self::PHPC_PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exhausted',
            ] as $errCode => $message
        ) {
            $result = $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $code, $i32->constInt($errCode, false)),
                self::literalCstr($context, $message),
                $result
            );
        }

        return $result;
    }

    private static function emitMatch(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('cm_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__phpc_preg_match_internal'),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
    }

    private static function emitMatchAll(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('cma_entry');
        $context->builder->positionAtEnd($entry);

        $pattern = $fn->getParam(0);
        $subject = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $negOneI64 = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);

        $pregErrorSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $code = $context->builder->call(
            $context->lookupFunction('__phpc_preg_compile'),
            $pattern,
            $pregErrorSlot
        );
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $i8p->constNull());
        $compileFailBb = $fn->appendBasicBlock('cma_compile_fail');
        $createMdBb = $fn->appendBasicBlock('cma_create_md');
        $context->builder->branchIf($codeNull, $compileFailBb, $createMdBb);

        $context->builder->positionAtEnd($compileFailBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $context->builder->load($pregErrorSlot)
        );
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($createMdBb);
        $matchData = $context->builder->call(
            $context->lookupFunction('pcre2_match_data_create_from_pattern_8'),
            $code,
            $voidPtr->constNull()
        );
        $mdNull = $context->builder->icmp(Builder::INT_EQ, $matchData, $voidPtr->constNull());
        $initLoopBb = $fn->appendBasicBlock('cma_init_loop');
        $mdFailBb = $fn->appendBasicBlock('cma_md_fail');
        $context->builder->branchIf($mdNull, $mdFailBb, $initLoopBb);

        $context->builder->positionAtEnd($mdFailBb);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_INTERNAL_ERROR, false)
        );
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($initLoopBb);
        $subj = self::stringData($context, $subject);
        $subjLen = $context->builder->truncOrBitCast(self::stringLen($context, $subject), $sizeT);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $startOffsetSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zeroI64, $countSlot);
        $context->builder->store($sizeT->constInt(0, false), $startOffsetSlot);

        $loopHead = $fn->appendBasicBlock('cma_loop_head');
        $loopBody = $fn->appendBasicBlock('cma_loop_body');
        $loopDone = $fn->appendBasicBlock('cma_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $startOffset = $context->builder->load($startOffsetSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_ULE, $startOffset, $subjLen);
        $context->builder->branchIf($continueLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $rc = $context->builder->call(
            $context->lookupFunction('pcre2_match_8'),
            $code,
            $subj,
            $subjLen,
            $startOffset,
            $i32->constInt(0, false),
            $matchData,
            $voidPtr->constNull()
        );
        $isError = $context->builder->icmp(Builder::INT_SLT, $rc, $i32->constInt(0, false));
        $isNomatch = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(self::PCRE2_ERROR_NOMATCH, true));
        $breakBb = $fn->appendBasicBlock('cma_break');
        $matchErrBb = $fn->appendBasicBlock('cma_match_err');
        $countBb = $fn->appendBasicBlock('cma_count');
        $checkNomatchBb = $fn->appendBasicBlock('cma_check_nomatch');
        $context->builder->branchIf($isError, $checkNomatchBb, $countBb);

        $context->builder->positionAtEnd($checkNomatchBb);
        $context->builder->branchIf($isNomatch, $breakBb, $matchErrBb);

        $context->builder->positionAtEnd($breakBb);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($matchErrBb);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $mapped = $context->builder->call($context->lookupFunction('__phpc_pcre2_error_to_preg'), $rc);
        $context->builder->call($context->lookupFunction('__phpc_preg_set_error'), $mapped);
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($countBb);
        $count = $context->builder->load($countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($count, $oneI64), $countSlot);
        $ovector = $context->builder->call(
            $context->lookupFunction('pcre2_get_ovector_pointer_8'),
            $matchData
        );
        $start = $context->builder->load($ovector);
        $end = $context->builder->load($context->builder->inBoundsGEP($ovector, $sizeT->constInt(1, false)));
        $samePos = $context->builder->icmp(Builder::INT_EQ, $end, $start);
        $nextOffset = $context->builder->select(
            $samePos,
            $context->builder->add($end, $sizeT->constInt(1, false)),
            $end
        );
        $context->builder->store($nextOffset, $startOffsetSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_UGT, $nextOffset, $subjLen);
        $afterAdvanceBb = $fn->appendBasicBlock('cma_after_advance');
        $stopBb = $fn->appendBasicBlock('cma_stop');
        $context->builder->branchIf($pastEnd, $stopBb, $afterAdvanceBb);
        $context->builder->positionAtEnd($stopBb);
        $context->builder->branch($loopDone);
        $context->builder->positionAtEnd($afterAdvanceBb);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('pcre2_match_data_free_8'), $matchData);
        $context->builder->call($context->lookupFunction('pcre2_code_free_8'), $code);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $i32->constInt(self::PHPC_PREG_NO_ERROR, false)
        );
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitReplace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('cr_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__phpc_preg_replace_internal'),
                $fn->getParam(0),
                $fn->getParam(1),
                $fn->getParam(2)
            )
        );
    }

    private static function emitReplaceCallbackStub(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('crc_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $context->getTypeFromString('int32')->constInt(self::PHPC_PREG_BAD_REGEX, false)
        );
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
    }

    private static function emitSplitStub(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('cs_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction('__phpc_preg_set_error'),
            $context->getTypeFromString('int32')->constInt(self::PHPC_PREG_BAD_REGEX, false)
        );
        $context->builder->returnValue($context->getTypeFromString('__hashtable__*')->constNull());
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

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringPregMatchJit LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
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
}
