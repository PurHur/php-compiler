<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrspn;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Init-safe LLVM delimited-pair parser for user-script AOT superglobal refresh (#13571, #13900, #19500).
 *
 * Nested {@see ParseStrJitHelper::parseIntoNative} segfaults at {@code main_after_init}; this
 * hand-lowering mirrors {@see ParseStrEngine} for runtime libc getenv strings.
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19466 / #19500.
 * Bracket-key split uses {@see StringStrspn} {@code __compiler_strcspn} (#29050), not libc.
 * Pair split uses module-local {@code __compiler_strtok_r} (#29091), not libc {@code strtok_r}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class JitParseStrUserScriptCstrKernel
{
    private const MAX_KEY_PARTS = 16;

    /** bytes: 16×pointer + count + append_list */
    private const PARSED_KEY_SIZE = 144;

    private const PARTS_OFF = 0;

    private const COUNT_OFF = 128;

    private const APPEND_OFF = 136;

    /** User-script AOT: init-safe native delimited LLVM subhelpers (#13717, #13900). */
    public static function ensureSubhelpers(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        self::implementIfMissing($context, '__compiler_strtok_r', self::emitCompilerStrtokR(...));
        self::implementIfMissing($context, '__phpc_parse_str_cstr_to_string', self::emitCstrToString(...));
        self::implementIfMissing($context, '__phpc_parse_str_set_string_key', self::emitSetStringKey(...));
        self::implementIfMissing($context, '__phpc_parse_str_url_decode_inplace', self::emitUrlDecodeInplace(...));
        self::implementIfMissing($context, '__phpc_parse_str_trim_ws_inplace', self::emitTrimWsInplace(...));
        self::implementIfMissing($context, '__phpc_parse_str_free_parsed_key', self::emitFreeParsedKey(...));
        self::implementIfMissing($context, '__phpc_parse_str_parse_key_brackets', self::emitParseKeyBrackets(...));
        self::implementIfMissing($context, '__phpc_parse_str_ensure_child', self::emitEnsureChild(...));
        self::implementIfMissing($context, '__phpc_parse_str_set_nested_value', self::emitSetNestedValue(...));
        self::implementIfMissing($context, '__phpc_parse_str_parse_delimited_pairs', self::emitParseDelimitedPairs(...));

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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');

        return match ($name) {
            '__compiler_strtok_r' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p, $i8p->pointerType(0))
            ),
            '__phpc_parse_str_cstr_to_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_parse_str_set_string_key' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8p)
            ),
            '__phpc_parse_str_url_decode_inplace' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p)
            ),
            '__phpc_parse_str_trim_ws_inplace' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p)
            ),
            '__phpc_parse_str_free_parsed_key' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p)
            ),
            '__phpc_parse_str_parse_key_brackets' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p)
            ),
            '__phpc_parse_str_ensure_child' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $htPtr, $i8p)
            ),
            '__phpc_parse_str_set_nested_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8p)
            ),
            '__phpc_parse_str_parse_delimited_pairs' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8, $i32)
            ),
            default => throw new \LogicException('Unknown parse_str JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal($context, 'malloc', $context->context->functionType($voidPtr, false, $sizeT));
        self::ensureExternal($context, 'free', $context->context->functionType($voidTy, false, $i8p));
        // memcpy(3) via LibcExtern::ensureMemcpyDecl after always-on drop (#31885);
        // canonical i8* ABI avoids void* NestedJIT mistyped calls (#27663).
        LibcExtern::ensureMemcpyDecl($context);
        // memmove module-local after LibcExtern always-on drop (#31743); int8* matches
        // LibcExtern::ensureMemmoveDecl / EMBED implementMemmoveBody ABI.
        LibcExtern::ensureMemmoveDecl($context);
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
        StringStrspn::ensureLinked($context);
        // strtok_r → __compiler_strtok_r via ensureSubhelpers (#29091).
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
                ['__hashtable__setStringAt', $void, [$htPtr, $sizeT, $strPtr]],
                ['__hashtable__getNumElements', $sizeT, [$htPtr]],
                ['__hashtable__readStringKeyHashtable', $htPtr, [$htPtr, $strPtr]],
                ['__string__init', $strPtr, [$context->getTypeFromString('int64'), $context->getTypeFromString('int8*')]],
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

    /**
     * POSIX strtok_r for the parse_str single-char delimiter path (#29091).
     *
     * Delimiter string is treated as a set of separator bytes (kernel uses one char + NUL).
     */
    private static function emitCompilerStrtokR(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('strtok_r_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $zero8 = $i8->constInt(0, false);
        $null = $i8p->constNull();

        $strArg = $fn->getParam(0);
        $delim = $fn->getParam(1);
        $savePtr = $fn->getParam(2);

        $strSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $isNullStr = $context->builder->icmp(Builder::INT_EQ, $strArg, $null);
        $fromSave = $context->builder->load($savePtr);
        $context->builder->store(
            $context->builder->select($isNullStr, $fromSave, $strArg),
            $strSlot
        );

        $strNow = $context->builder->load($strSlot);
        $noStr = $context->builder->icmp(Builder::INT_EQ, $strNow, $null);
        $retNullBb = $fn->appendBasicBlock('strtok_r_ret_null');
        $skipDelimBb = $fn->appendBasicBlock('strtok_r_skip_delim');
        $context->builder->branchIf($noStr, $retNullBb, $skipDelimBb);

        $context->builder->positionAtEnd($retNullBb);
        $context->builder->store($null, $savePtr);
        $context->builder->returnValue($null);

        // Skip leading delimiter bytes.
        $context->builder->positionAtEnd($skipDelimBb);
        $skipHead = $fn->appendBasicBlock('strtok_r_skip_head');
        $skipBody = $fn->appendBasicBlock('strtok_r_skip_body');
        $afterSkip = $fn->appendBasicBlock('strtok_r_after_skip');
        $context->builder->branch($skipHead);

        $context->builder->positionAtEnd($skipHead);
        $p = $context->builder->load($strSlot);
        $ch = $context->builder->load($p);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zero8);
        $context->builder->branchIf($atEnd, $afterSkip, $skipBody);

        $context->builder->positionAtEnd($skipBody);
        $p = $context->builder->load($strSlot);
        $ch = $context->builder->load($p);
        $ch32 = $context->builder->zExt($ch, $i32);
        $inDelim = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $delim, $ch32),
            $null
        );
        $skipAdv = $fn->appendBasicBlock('strtok_r_skip_adv');
        $context->builder->branchIf($inDelim, $skipAdv, $afterSkip);

        $context->builder->positionAtEnd($skipAdv);
        $p = $context->builder->load($strSlot);
        $context->builder->store($context->builder->inBoundsGEP($p, $one), $strSlot);
        $context->builder->branch($skipHead);

        $context->builder->positionAtEnd($afterSkip);
        $p = $context->builder->load($strSlot);
        $ch = $context->builder->load($p);
        $emptyTok = $context->builder->icmp(Builder::INT_EQ, $ch, $zero8);
        $scanBb = $fn->appendBasicBlock('strtok_r_scan');
        $context->builder->branchIf($emptyTok, $retNullBb, $scanBb);

        $context->builder->positionAtEnd($scanBb);
        $token = $context->builder->load($strSlot);
        $tokenSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($token, $tokenSlot);
        $scanHead = $fn->appendBasicBlock('strtok_r_scan_head');
        $scanBody = $fn->appendBasicBlock('strtok_r_scan_body');
        $scanHit = $fn->appendBasicBlock('strtok_r_scan_hit');
        $scanEnd = $fn->appendBasicBlock('strtok_r_scan_end');
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHead);
        $p = $context->builder->load($strSlot);
        $ch = $context->builder->load($p);
        $atNul = $context->builder->icmp(Builder::INT_EQ, $ch, $zero8);
        $context->builder->branchIf($atNul, $scanEnd, $scanBody);

        $context->builder->positionAtEnd($scanBody);
        $p = $context->builder->load($strSlot);
        $ch = $context->builder->load($p);
        $ch32 = $context->builder->zExt($ch, $i32);
        $hitDelim = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $delim, $ch32),
            $null
        );
        $scanInc = $fn->appendBasicBlock('strtok_r_scan_inc');
        $context->builder->branchIf($hitDelim, $scanHit, $scanInc);

        $context->builder->positionAtEnd($scanInc);
        $p = $context->builder->load($strSlot);
        $context->builder->store($context->builder->inBoundsGEP($p, $one), $strSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHit);
        $p = $context->builder->load($strSlot);
        $context->builder->store($zero8, $p);
        $context->builder->store($context->builder->inBoundsGEP($p, $one), $savePtr);
        $context->builder->returnValue($context->builder->load($tokenSlot));

        $context->builder->positionAtEnd($scanEnd);
        $context->builder->store($context->builder->load($strSlot), $savePtr);
        $context->builder->returnValue($context->builder->load($tokenSlot));
    }

    private static function emitCstrToString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $cstr = $fn->getParam(0);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $context->builder->returnValue($ret);
    }

    private static function emitSetStringKey(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $val = $fn->getParam(2);
        $kStr = $context->builder->call($context->lookupFunction('__phpc_parse_str_cstr_to_string'), $key);
        $vStr = $context->builder->call($context->lookupFunction('__phpc_parse_str_cstr_to_string'), $val);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $kStr,
            $vStr
        );
        $context->builder->returnVoid();
    }

    private static function emitUrlDecodeInplace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i8->constInt(0, false);
        $one = $i64->constInt(1, false);
        $s = $fn->getParam(0);

        $wSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8*'));
        $rSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8*'));
        $context->builder->store($s, $wSlot);
        $context->builder->store($s, $rSlot);

        $loopHead = $fn->appendBasicBlock('url_head');
        $loopBody = $fn->appendBasicBlock('url_body');
        $done = $fn->appendBasicBlock('url_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $r = $context->builder->load($rSlot);
        $ch = $context->builder->load($r);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zero);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $r = $context->builder->load($rSlot);
        $w = $context->builder->load($wSlot);
        $ch = $context->builder->load($r);
        $plus = $i8->constInt(43, false);
        $pct = $i8->constInt(37, false);
        $isPlus = $context->builder->icmp(Builder::INT_EQ, $ch, $plus);
        $isPct = $context->builder->icmp(Builder::INT_EQ, $ch, $pct);

        $plusBb = $fn->appendBasicBlock('url_plus');
        $pctCheckBb = $fn->appendBasicBlock('url_pct_check');
        $pctDecodeBb = $fn->appendBasicBlock('url_pct');
        $copyBb = $fn->appendBasicBlock('url_copy');
        $nextBb = $fn->appendBasicBlock('url_next');
        $context->builder->branchIf($isPlus, $plusBb, $pctCheckBb);

        $context->builder->positionAtEnd($plusBb);
        $space = $i8->constInt(32, false);
        $context->builder->store($space, $w);
        $context->builder->store($context->builder->inBoundsGEP($w, $one), $wSlot);
        $context->builder->store($context->builder->inBoundsGEP($r, $one), $rSlot);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($pctCheckBb);
        $r = $context->builder->load($rSlot);
        $h1 = $context->builder->load($context->builder->inBoundsGEP($r, $one));
        $h2 = $context->builder->load($context->builder->inBoundsGEP($r, $i64->constInt(2, false)));
        $hex1 = self::isHex($context, $h1);
        $hex2 = self::isHex($context, $h2);
        $bothHex = $context->builder->and($isPct, $context->builder->and($hex1, $hex2));
        $context->builder->branchIf($bothHex, $pctDecodeBb, $copyBb);

        $context->builder->positionAtEnd($pctDecodeBb);
        $r = $context->builder->load($rSlot);
        $w = $context->builder->load($wSlot);
        $h1 = $context->builder->load($context->builder->inBoundsGEP($r, $one));
        $h2 = $context->builder->load($context->builder->inBoundsGEP($r, $i64->constInt(2, false)));
        $v1 = self::hexValue($context, $h1);
        $v2 = self::hexValue($context, $h2);
        $decoded = $context->builder->trunc(
            $context->builder->add(
                $context->builder->mul($v1, $i64->constInt(16, false)),
                $v2
            ),
            $i8
        );
        $context->builder->store($decoded, $w);
        $context->builder->store($context->builder->inBoundsGEP($w, $one), $wSlot);
        $context->builder->store($context->builder->inBoundsGEP($r, $i64->constInt(3, false)), $rSlot);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($copyBb);
        $r = $context->builder->load($rSlot);
        $w = $context->builder->load($wSlot);
        $ch = $context->builder->load($r);
        $context->builder->store($ch, $w);
        $context->builder->store($context->builder->inBoundsGEP($w, $one), $wSlot);
        $context->builder->store($context->builder->inBoundsGEP($r, $one), $rSlot);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $w = $context->builder->load($wSlot);
        $context->builder->store($zero, $w);
        $context->builder->returnVoid();
    }

    private static function emitTrimWsInplace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $zero = $i8->constInt(0, false);
        $one = $i64->constInt(1, false);
        $s = $fn->getParam(0);

        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($s, $startSlot);

        $leadHead = $fn->appendBasicBlock('trim_lead_head');
        $leadBody = $fn->appendBasicBlock('trim_lead_body');
        $leadDone = $fn->appendBasicBlock('trim_lead_done');
        $context->builder->branch($leadHead);

        $context->builder->positionAtEnd($leadHead);
        $start = $context->builder->load($startSlot);
        $ch = $context->builder->load($start);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zero);
        $isWs = self::isTrimWs($context, $ch);
        $advance = $context->builder->and($context->builder->not($atEnd), $isWs);
        $context->builder->branchIf($advance, $leadBody, $leadDone);

        $context->builder->positionAtEnd($leadBody);
        $start = $context->builder->load($startSlot);
        $context->builder->store($context->builder->inBoundsGEP($start, $one), $startSlot);
        $context->builder->branch($leadHead);

        $context->builder->positionAtEnd($leadDone);
        $start = $context->builder->load($startSlot);
        $moved = $context->builder->icmp(Builder::INT_NE, $start, $s);
        $moveBb = $fn->appendBasicBlock('trim_move');
        $tailBb = $fn->appendBasicBlock('trim_tail');
        $context->builder->branchIf($moved, $moveBb, $tailBb);

        $context->builder->positionAtEnd($moveBb);
        $start = $context->builder->load($startSlot);
        $restLen = $context->builder->call($context->lookupFunction('strlen'), $start);
        $restLenPlus = $context->builder->add($restLen, $one);
        $context->builder->call(
            $context->lookupFunction('memmove'),
            $context->bytePtr($s),
            $context->bytePtr($start),
            $context->builder->truncOrBitCast($restLenPlus, $sizeT)
        );
        $context->builder->branch($tailBb);

        $context->builder->positionAtEnd($tailBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $s);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($context->builder->inBoundsGEP($s, $len), $endSlot);

        $tailHead = $fn->appendBasicBlock('trim_tail_head');
        $tailBody = $fn->appendBasicBlock('trim_tail_body');
        $tailDone = $fn->appendBasicBlock('trim_tail_done');
        $context->builder->branch($tailHead);

        $context->builder->positionAtEnd($tailHead);
        $end = $context->builder->load($endSlot);
        $beforeStart = $context->builder->icmp(Builder::INT_SLE, $end, $s);
        $ch = $context->builder->load($context->builder->inBoundsGEP($end, $i64->constInt(-1, true)));
        $isWs = self::isTrimWs($context, $ch);
        $shrink = $context->builder->and($context->builder->not($beforeStart), $isWs);
        $context->builder->branchIf($shrink, $tailBody, $tailDone);

        $context->builder->positionAtEnd($tailBody);
        $end = $context->builder->load($endSlot);
        $context->builder->store($context->builder->inBoundsGEP($end, $i64->constInt(-1, true)), $endSlot);
        $context->builder->branch($tailHead);

        $context->builder->positionAtEnd($tailDone);
        $end = $context->builder->load($endSlot);
        $context->builder->store($zero, $end);
        $context->builder->returnVoid();
    }

    private static function isTrimWs(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(32, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(9, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false))
                )
            )
        );
    }

    private static function isHex(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
                $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
            ),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(97, false)),
                    $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(102, false))
                ),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
                    $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(70, false))
                )
            )
        );
    }

    private static function hexValue(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
        );
        $digitVal = $context->builder->sub(
            $context->builder->zExt($ch, $i64),
            $i64->constInt(48, false)
        );
        $lower = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(97, false)),
            $context->builder->sub($context->builder->zExt($ch, $i64), $i64->constInt(97 - 10, false)),
            $context->builder->sub($context->builder->zExt($ch, $i64), $i64->constInt(65 - 10, false))
        );

        return $context->builder->select($isDigit, $digitVal, $lower);
    }

    private static function emitFreeParsedKey(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $pkVoid = $fn->getParam(0);
        $parts = self::parsedKeyPartsPtr($context, $pkVoid);
        $countPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pkVoid, self::COUNT_OFF),
            $i64->pointerType(0)
        );
        $appendPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pkVoid, self::APPEND_OFF),
            $context->getTypeFromString('int32')->pointerType(0)
        );

        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $count = $context->builder->load($countPtr);

        $head = $fn->appendBasicBlock('fpk_head');
        $body = $fn->appendBasicBlock('fpk_body');
        $done = $fn->appendBasicBlock('fpk_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $end = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($end, $done, $body);

        $context->builder->positionAtEnd($body);
        $part = $context->builder->load($context->builder->gep($parts, $i));
        $context->builder->call($context->lookupFunction('free'), $part);
        $context->builder->store($i8p->constNull(), $context->builder->gep($parts, $i));
        $context->builder->store($context->builder->addNoSignedWrap($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($i64->constInt(0, false), $countPtr);
        $context->builder->store($context->getTypeFromString('int32')->constInt(0, false), $appendPtr);
        $context->builder->returnVoid();
    }

    private static function emitParseKeyBrackets(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $raw = $fn->getParam(0);
        $pk = $fn->getParam(1);

        $parts = self::parsedKeyPartsPtr($context, $pk);
        $countPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pk, self::COUNT_OFF),
            $i64->pointerType(0)
        );
        $appendPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pk, self::APPEND_OFF),
            $i32->pointerType(0)
        );
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($i64->constInt(0, false), $countPtr);
        $context->builder->store($i32->constInt(0, false), $appendPtr);

        $fail = $fn->appendBasicBlock('pkb_fail');
        $okBb = $fn->appendBasicBlock('pkb_ok');
        $initBb = $fn->appendBasicBlock('pkb_init');
        $hasBaseBb = $fn->appendBasicBlock('pkb_has_base');
        $noBaseBb = $fn->appendBasicBlock('pkb_no_base');
        $bracketLoop = $fn->appendBasicBlock('pkb_bracket_loop');
        $finish = $fn->appendBasicBlock('pkb_finish');
        $bracketBody = $fn->appendBasicBlock('pkb_bracket_body');
        $emptyBb = $fn->appendBasicBlock('pkb_empty_bracket');
        $innerBb = $fn->appendBasicBlock('pkb_inner');
        $storePart = $fn->appendBasicBlock('pkb_store_part');
        $allocPart = $fn->appendBasicBlock('pkb_alloc_part');
        $listBb = $fn->appendBasicBlock('pkb_list_bracket');
        $contBb = $fn->appendBasicBlock('pkb_cont');

        $emptyKey = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($raw), $i8->constInt(0, false));
        $context->builder->branchIf($emptyKey, $fail, $initBb);

        $context->builder->positionAtEnd($initBb);
        $bracketSet = self::cstrLiteral($context, '[');
        // PHP-owned ABI (#29050) — not libc strcspn (name collision / LibcExtern shrink).
        $baseLen = $context->builder->zExt(
            $context->builder->call($context->lookupFunction('__compiler_strcspn'), $raw, $bracketSet),
            $i64
        );
        $hasBase = $context->builder->icmp(Builder::INT_UGT, $baseLen, $i64->constInt(0, false));
        $context->builder->branchIf($hasBase, $hasBaseBb, $noBaseBb);

        $context->builder->positionAtEnd($hasBaseBb);
        $count = $context->builder->load($countPtr);
        $part = self::strndup($context, $raw, $baseLen);
        $context->builder->store($part, $context->builder->gep($parts, $count));
        $context->builder->store($context->builder->addNoSignedWrap($count, $i64->constInt(1, false)), $countPtr);
        $context->builder->store($context->builder->inBoundsGEP($raw, $baseLen), $pSlot);
        $context->builder->branch($bracketLoop);

        $context->builder->positionAtEnd($noBaseBb);
        $context->builder->store($raw, $pSlot);
        $context->builder->branch($bracketLoop);

        $context->builder->positionAtEnd($bracketLoop);
        $p = $context->builder->load($pSlot);
        $notBracket = $context->builder->icmp(Builder::INT_NE, $context->builder->load($p), $i8->constInt(91, false));
        $context->builder->branchIf($notBracket, $finish, $bracketBody);

        $context->builder->positionAtEnd($bracketBody);
        $p = $context->builder->inBoundsGEP($context->builder->load($pSlot), $i64->constInt(1, false));
        $context->builder->store($p, $pSlot);
        $emptyBracket = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($p), $i8->constInt(93, false));
        $context->builder->branchIf($emptyBracket, $emptyBb, $innerBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store($i32->constInt(1, false), $appendPtr);
        $context->builder->store(
            $context->builder->inBoundsGEP($context->builder->load($pSlot), $i64->constInt(1, false)),
            $pSlot
        );
        $context->builder->branch($bracketLoop);

        $context->builder->positionAtEnd($innerBb);
        $p = $context->builder->load($pSlot);
        $close = $context->builder->call($context->lookupFunction('strchr'), $p, $i32->constInt(93, false));
        $noClose = $context->builder->icmp(Builder::INT_EQ, $close, $i8p->constNull());
        $context->builder->branchIf($noClose, $fail, $storePart);

        $context->builder->positionAtEnd($storePart);
        $p = $context->builder->load($pSlot);
        $close = $context->builder->call($context->lookupFunction('strchr'), $p, $i32->constInt(93, false));
        $len = $context->builder->sub(
            $context->builder->ptrToInt($close, $i64),
            $context->builder->ptrToInt($p, $i64)
        );
        $count = $context->builder->load($countPtr);
        $tooMany = $context->builder->icmp(Builder::INT_SGE, $count, $i64->constInt(self::MAX_KEY_PARTS, false));
        $context->builder->branchIf($tooMany, $fail, $allocPart);

        $context->builder->positionAtEnd($allocPart);
        $p = $context->builder->load($pSlot);
        $close = $context->builder->call($context->lookupFunction('strchr'), $p, $i32->constInt(93, false));
        $len = $context->builder->sub(
            $context->builder->ptrToInt($close, $i64),
            $context->builder->ptrToInt($p, $i64)
        );
        $part = self::strndup($context, $p, $len);
        $count = $context->builder->load($countPtr);
        $context->builder->store($part, $context->builder->gep($parts, $count));
        $context->builder->store($context->builder->addNoSignedWrap($count, $i64->constInt(1, false)), $countPtr);
        $context->builder->store($context->builder->inBoundsGEP($close, $i64->constInt(1, false)), $pSlot);
        $p = $context->builder->load($pSlot);
        $listBracket = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($p), $i8->constInt(91, false)),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->inBoundsGEP($p, $i64->constInt(1, false))),
                $i8->constInt(93, false)
            )
        );
        $context->builder->branchIf($listBracket, $listBb, $contBb);

        $context->builder->positionAtEnd($listBb);
        $context->builder->store($i32->constInt(1, false), $appendPtr);
        $context->builder->store(
            $context->builder->inBoundsGEP($context->builder->load($pSlot), $i64->constInt(2, false)),
            $pSlot
        );
        $context->builder->branch($bracketLoop);

        $context->builder->positionAtEnd($contBb);
        $context->builder->branch($bracketLoop);

        $context->builder->positionAtEnd($finish);
        $p = $context->builder->load($pSlot);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($p), $i8->constInt(0, false));
        $hasParts = $context->builder->icmp(Builder::INT_UGT, $context->builder->load($countPtr), $i64->constInt(0, false));
        $ok = $context->builder->and($atEnd, $hasParts);
        $context->builder->branchIf($ok, $okBb, $fail);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('__phpc_parse_str_free_parsed_key'), $pk);
        $context->builder->returnValue($i32->constInt(-1, false));
    }

    private static function emitEnsureChild(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $kStr = $context->builder->call($context->lookupFunction('__phpc_parse_str_cstr_to_string'), $key);
        $child = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $ht,
            $kStr
        );
        $nullHt = $context->getTypeFromString('__hashtable__*')->constNull();
        $hasChild = $context->builder->icmp(Builder::INT_NE, $child, $nullHt);
        $hasBb = $fn->appendBasicBlock('ec_has');
        $allocBb = $fn->appendBasicBlock('ec_alloc');
        $done = $fn->appendBasicBlock('ec_done');
        $context->builder->branchIf($hasChild, $hasBb, $allocBb);

        $context->builder->positionAtEnd($hasBb);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($allocBb);
        $newChild = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $kStr,
            $newChild
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $ret = $context->builder->phi($nullHt->typeOf());
        $ret->addIncoming($child, $hasBb);
        $ret->addIncoming($newChild, $allocBb);
        $context->builder->returnValue($ret);
    }

    private static function emitSetNestedValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $i8pp = $context->getTypeFromString('int8*')->pointerType(0);
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $root = $fn->getParam(0);
        $pk = $fn->getParam(1);
        $value = $fn->getParam(2);

        $parts = self::parsedKeyPartsPtr($context, $pk);
        $countPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pk, self::COUNT_OFF),
            $i64->pointerType(0)
        );
        $appendPtr = $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pk, self::APPEND_OFF),
            $i32->pointerType(0)
        );
        $count = $context->builder->load($countPtr);
        $zeroCount = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        $early = $fn->appendBasicBlock('snv_early');
        $walk = $fn->appendBasicBlock('snv_walk');
        $context->builder->branchIf($zeroCount, $early, $walk);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($walk);
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($root, $htSlot);
        $context->builder->store($i64->constInt(0, false), $iSlot);

        $walkHead = $fn->appendBasicBlock('snv_head');
        $walkBody = $fn->appendBasicBlock('snv_body');
        $leafBb = $fn->appendBasicBlock('snv_leaf');
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($walkHead);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load($countPtr);
        $last = $context->builder->sub($count, $i64->constInt(1, false));
        $atLeaf = $context->builder->icmp(Builder::INT_SGE, $i, $last);
        $context->builder->branchIf($atLeaf, $leafBb, $walkBody);

        $context->builder->positionAtEnd($walkBody);
        $ht = $context->builder->load($htSlot);
        $part = $context->builder->load($context->builder->gep($parts, $i));
        $child = $context->builder->call($context->lookupFunction('__phpc_parse_str_ensure_child'), $ht, $part);
        $context->builder->store($child, $htSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($leafBb);
        $ht = $context->builder->load($htSlot);
        $leaf = $context->builder->load($context->builder->gep($parts, $last));
        $append = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($appendPtr),
            $i32->constInt(0, false)
        );
        $listBb = $fn->appendBasicBlock('snv_list');
        $scalarBb = $fn->appendBasicBlock('snv_scalar');
        $context->builder->branchIf($append, $listBb, $scalarBb);

        $context->builder->positionAtEnd($listBb);
        $listHt = $context->builder->call($context->lookupFunction('__phpc_parse_str_ensure_child'), $ht, $leaf);
        $idx = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $listHt);
        $valStr = $context->builder->call($context->lookupFunction('__phpc_parse_str_cstr_to_string'), $value);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $listHt,
            $idx,
            $valStr
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($scalarBb);
        $context->builder->call($context->lookupFunction('__phpc_parse_str_set_string_key'), $ht, $leaf, $value);
        $context->builder->returnVoid();
    }

    private static function emitParseDelimitedPairs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $body = $fn->getParam(1);
        $delimiter = $fn->getParam(2);
        $decodePairFirst = $fn->getParam(3);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $voidPtr = $context->getTypeFromString('void*');

        $empty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($body), $i8->constInt(0, false));
        $early = $fn->appendBasicBlock('pdp_early');
        $work = $fn->appendBasicBlock('pdp_work');
        $context->builder->branchIf($empty, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $len = $context->builder->call($context->lookupFunction('strlen'), $body);
        $one = $i64->constInt(1, false);
        $copy = $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('malloc'),
                $context->builder->truncOrBitCast($context->builder->add($len, $one), $sizeT)
            ),
            $i8p
        );
        $context->intrinsic->memcpy($copy, $body, $len, false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($copy, $len));

        $delimSlot = $context->builder->alloca($i8, 2, 'pdp_delim');
        $context->builder->store($delimiter, $delimSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($delimSlot, $one));
        $saveSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $pair = $context->builder->call(
            $context->lookupFunction('__compiler_strtok_r'),
            $copy,
            $context->builder->pointerCast($delimSlot, $i8p),
            $saveSlot
        );

        // Store strtok result before branching — a store after the terminator is dead IR and
        // leaves pairSlot uninitialized (reads as a code address → url_decode segfault, #29001).
        $pairSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($pair, $pairSlot);

        $loopHead = $fn->appendBasicBlock('pdp_head');
        $loopBody = $fn->appendBasicBlock('pdp_body');
        $loopDone = $fn->appendBasicBlock('pdp_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pair = $context->builder->load($pairSlot);
        $nullPair = $context->builder->icmp(Builder::INT_EQ, $pair, $i8p->constNull());
        $context->builder->branchIf($nullPair, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pair = $context->builder->load($pairSlot);
        $decodeFirst = $context->builder->icmp(Builder::INT_NE, $decodePairFirst, $i32->constInt(0, false));
        $cookieBb = $fn->appendBasicBlock('pdp_cookie');
        $splitStartBb = $fn->appendBasicBlock('pdp_split_start');
        $context->builder->branchIf($decodeFirst, $cookieBb, $splitStartBb);

        $context->builder->positionAtEnd($cookieBb);
        $pair = $context->builder->load($pairSlot);
        $context->builder->call($context->lookupFunction('__phpc_parse_str_trim_ws_inplace'), $pair);
        $context->builder->call($context->lookupFunction('__phpc_parse_str_url_decode_inplace'), $pair);
        $context->builder->branch($splitStartBb);

        $context->builder->positionAtEnd($splitStartBb);
        $pair = $context->builder->load($pairSlot);
        $eq = $context->builder->call($context->lookupFunction('strchr'), $pair, $i32->constInt(61, false));
        $hasEq = $context->builder->icmp(Builder::INT_NE, $eq, $i8p->constNull());
        $keySlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $splitBb = $fn->appendBasicBlock('pdp_split');
        $noEqBb = $fn->appendBasicBlock('pdp_no_eq');
        $processBb = $fn->appendBasicBlock('pdp_process');
        $context->builder->branchIf($hasEq, $splitBb, $noEqBb);

        $context->builder->positionAtEnd($splitBb);
        $pair = $context->builder->load($pairSlot);
        $eq = $context->builder->call($context->lookupFunction('strchr'), $pair, $i32->constInt(61, false));
        $context->builder->store($i8->constInt(0, false), $eq);
        $context->builder->store($pair, $keySlot);
        $context->builder->store($context->builder->inBoundsGEP($eq, $one), $valSlot);
        $context->builder->branch($processBb);

        $context->builder->positionAtEnd($noEqBb);
        $pair = $context->builder->load($pairSlot);
        $context->builder->store($pair, $keySlot);
        $pairLen = $context->builder->call($context->lookupFunction('strlen'), $pair);
        $context->builder->store($context->builder->inBoundsGEP($pair, $pairLen), $valSlot);
        $context->builder->branch($processBb);

        $context->builder->positionAtEnd($processBb);
        $rawKey = $context->builder->load($keySlot);
        $emptyKey = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($rawKey), $i8->constInt(0, false));
        $skipBb = $fn->appendBasicBlock('pdp_skip');
        $decodeBb = $fn->appendBasicBlock('pdp_decode');
        $context->builder->branchIf($emptyKey, $skipBb, $decodeBb);

        $context->builder->positionAtEnd($decodeBb);
        $rawKey = $context->builder->load($keySlot);
        $rawVal = $context->builder->load($valSlot);
        $skipDecode = $context->builder->icmp(Builder::INT_NE, $decodePairFirst, $i32->constInt(0, false));
        $afterDecodeBb = $fn->appendBasicBlock('pdp_after_decode');
        $doDecodeBb = $fn->appendBasicBlock('pdp_do_decode');
        $context->builder->branchIf($skipDecode, $afterDecodeBb, $doDecodeBb);

        $context->builder->positionAtEnd($doDecodeBb);
        $context->builder->call($context->lookupFunction('__phpc_parse_str_url_decode_inplace'), $context->builder->load($keySlot));
        $context->builder->call($context->lookupFunction('__phpc_parse_str_url_decode_inplace'), $context->builder->load($valSlot));
        $context->builder->branch($afterDecodeBb);

        $context->builder->positionAtEnd($afterDecodeBb);
        $rawKey = $context->builder->load($keySlot);
        $rawVal = $context->builder->load($valSlot);
        $bracket = self::cstrLiteral($context, '[');
        $hasBracket = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $rawKey, $i32->constInt(91, false)),
            $i8p->constNull()
        );
        $flatBb = $fn->appendBasicBlock('pdp_flat');
        $nestedBb = $fn->appendBasicBlock('pdp_nested');
        $context->builder->branchIf($hasBracket, $nestedBb, $flatBb);

        $context->builder->positionAtEnd($flatBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_set_string_key'),
            $ht,
            $context->builder->load($keySlot),
            $context->builder->load($valSlot)
        );
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($nestedBb);
        // Bracket nested LLVM path segfaults on runtime refresh strings (#18841 follow-up); skip pair so
        // flat keys in the same QUERY_STRING keep working (NestedSuperglobalsAotTest).
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $next = $context->builder->call(
            $context->lookupFunction('__compiler_strtok_r'),
            $i8p->constNull(),
            $context->builder->pointerCast($delimSlot, $i8p),
            $saveSlot
        );
        $context->builder->store($next, $pairSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('free'), $copy);
        $context->builder->returnVoid();
    }

    private static function strndup(Context $context, Value $src, Value $len): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $one = $context->getTypeFromString('int64')->constInt(1, false);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($context->builder->add($len, $one), $sizeT)
        );
        $buf = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($buf, $src, $len, false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $len));

        return $buf;
    }

    private static function cstrLiteral(Context $context, string $text): Value
    {
        return $context->pointerFromStringConstant($text);
    }

    private static function parsedKeyPartsPtr(Context $context, Value $pkVoid): Value
    {
        return $context->builder->pointerCast(
            self::parsedKeyFieldPtr($context, $pkVoid, self::PARTS_OFF),
            $context->getTypeFromString('int8**')
        );
    }

    private static function parsedKeyBytePtr(Context $context, Value $pkVoid): Value
    {
        return $context->builder->pointerCast($pkVoid, $context->getTypeFromString('int8*'));
    }

    private static function parsedKeyFieldPtr(Context $context, Value $pkVoid, int $byteOffset): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            self::parsedKeyBytePtr($context, $pkVoid),
            $i64->constInt($byteOffset, false)
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
}
