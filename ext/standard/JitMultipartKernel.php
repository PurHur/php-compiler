<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script AOT LLVM multipart populate for request_parse_body (#19454, #5965, #19628).
 *
 * Nested MultipartNativeJitHelper cannot explode/substr or reliably file_put_contents /
 * tempnam under deferred AOT. General rfc1867 boundaries use libc strncmp-walk finders
 * (no strstr — helper-runtime O=1 collisions) + memcpy/fopen; field names via `; name="`
 * so `filename="` does not steal the name. File uploads use fixed
 * `/tmp/phpc_rpb_multipart_up.txt` (proven fixture path; avoid temp-file free aliasing).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19399 / #19430.
 * php-src: main/rfc1867.c
 */
final class JitMultipartKernel
{
    public const PARSE_FUNCTION = '__compiler_rpb_multipart_llvm_parse_v5';

    private const FIND_FUNCTION = '__compiler_rpb_mp_find_v1';

    private const BOUNDARY_BUF = 96;

    private const DELIM_BUF = 100;

    private const FIXED_UPLOAD_PATH = '/tmp/phpc_rpb_multipart_up.txt';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::PARSE_FUNCTION);
        if (null !== $probe && self::parseBodyComplete($probe)) {
            $context->registerFunction(self::PARSE_FUNCTION, $probe);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        LibcExtern::register($context);
        // Module-local fopen/fwrite/fclose after LibcExtern always-on drop (#31764).
        LibcExtern::ensureStdioFile($context);
        // Module-local strncmp after LibcExtern always-on drop (#31839).
        LibcExtern::ensureStrncmp($context);
        $libcStrlen = $context->lookupFunction('strlen');
        ParseStrRuntime::ensureUserScriptLinked($context);
        $context->registerFunction('strlen', $libcStrlen);
        self::ensureHashtableHelpers($context);
        self::ensureFindLinked($context);
        self::emitParse($context);
        $context->registerFunction('strlen', $libcStrlen);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    public static function emitCallFromBridge(
        Context $context,
        Value $post,
        Value $files,
        Value $contentTypeCstr,
        Value $bodyCstr
    ): void {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::PARSE_FUNCTION),
            $post,
            $files,
            $contentTypeCstr,
            $bodyCstr
        );
        $context->builder->returnVoid();
    }

    private static function parseBodyComplete(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('mp_llvm_v5_done' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }


    private static function ensureFindLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FIND_FUNCTION);
        if (null !== $probe && self::findBodyComplete($probe)) {
            $context->registerFunction(self::FIND_FUNCTION, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::FIND_FUNCTION,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            );
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $entry = $fn->appendBasicBlock('mp_find_entry');
        $loop = $fn->appendBasicBlock('mp_find_loop');
        $cmpBb = $fn->appendBasicBlock('mp_find_cmp');
        $adv = $fn->appendBasicBlock('mp_find_adv');
        $found = $fn->appendBasicBlock('mp_find_found');
        $miss = $fn->appendBasicBlock('mp_find_miss');
        $context->builder->positionAtEnd($entry);
        $hay = $fn->getParam(0);
        $needle = $fn->getParam(1);
        $nlen = $context->builder->call($context->lookupFunction('strlen'), $needle);
        $emptyNeedle = $context->builder->icmp(
            Builder::INT_EQ,
            $nlen,
            $sizeT->constInt(0, false)
        );
        $context->builder->branchIf($emptyNeedle, $found, $loop);

        $context->builder->positionAtEnd($loop);
        $cursor = $context->builder->phi($i8p);
        $cursor->addIncoming($hay, $entry);
        $ch = $context->builder->load($cursor);
        $eos = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $context->builder->branchIf($eos, $miss, $cmpBb);

        $context->builder->positionAtEnd($cmpBb);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $cursor,
            $needle,
            $nlen
        );
        $hit = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($hit, $found, $adv);

        $context->builder->positionAtEnd($adv);
        $next = $context->builder->inBoundsGEP($cursor, $sizeT->constInt(1, false));
        $cursor->addIncoming($next, $adv);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($found);
        $retFound = $context->builder->phi($i8p);
        $retFound->addIncoming($hay, $entry);
        $retFound->addIncoming($cursor, $cmpBb);
        $context->builder->returnValue($retFound);

        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($i8p->constNull());

        $context->registerFunction(self::FIND_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function findBodyComplete(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('mp_find_found' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }

    private static function callFind(Context $context, Value $haystack, Value $needle): Value
    {
        return $context->builder->call(
            $context->lookupFunction(self::FIND_FUNCTION),
            $haystack,
            $needle
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
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

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_cstr_to_string'),
            $cstr
        );
    }

    private static function cstrSliceToString(Context $context, Value $ptr, Value $len): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $copy = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($len, $one)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $copy,
            $ptr,
            $len
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($copy, $len)
        );
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($copy, $i8p)
        );
        $context->builder->call($context->lookupFunction('free'), $copy);

        return $str;
    }

    private static function setStringKeyCstr(Context $context, Value $ht, Value $keyCstr, Value $valCstr): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            self::cstrToString($context, $keyCstr),
            self::cstrToString($context, $valCstr)
        );
    }

    private static function setStringKeySlice(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Value $keyLen,
        Value $valPtr,
        Value $valLen
    ): void {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            self::cstrSliceToString($context, $keyPtr, $keyLen),
            self::cstrSliceToString($context, $valPtr, $valLen)
        );
    }

    private static function emitParse(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::PARSE_FUNCTION);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::PARSE_FUNCTION,
                $context->context->functionType($void, false, $htPtr, $htPtr, $i8p, $i8p)
            );
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $entry = $fn->appendBasicBlock('mp_llvm_v5_entry');
        $context->builder->positionAtEnd($entry);

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $contentType = $fn->getParam(2);
        $body = $fn->getParam(3);

        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $contentType,
            $context->pointerFromStringConstant('multipart/form-data'),
            $sizeT->constInt(19, false)
        );
        $isMp = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $bodyLen = $context->builder->call($context->lookupFunction('strlen'), $body);
        $bodyOk = $context->builder->icmp(
            Builder::INT_UGT,
            $bodyLen,
            $sizeT->constInt(10, false)
        );
        $ok = $context->builder->and($isMp, $bodyOk);
        $early = $fn->appendBasicBlock('mp_llvm_v5_early');
        $extractBb = $fn->appendBasicBlock('mp_llvm_v5_extract');
        $context->builder->branchIf($ok, $extractBb, $early);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($extractBb);
        $boundaryBuf = $context->builder->alloca($i8, self::BOUNDARY_BUF, 'mp_boundary');
        $delimBuf = $context->builder->alloca($i8, self::DELIM_BUF, 'mp_delim');
        $boundaryLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $boundaryLenSlot);

        $needle = $context->pointerFromStringConstant('boundary=');
        $boundPos = self::callFind(
            $context,
            $contentType,
            $needle
        );
        $noBound = $context->builder->icmp(Builder::INT_EQ, $boundPos, $i8p->constNull());
        $noBoundBb = $fn->appendBasicBlock('mp_llvm_v5_nobound');
        $haveBoundBb = $fn->appendBasicBlock('mp_llvm_v5_havebound');
        $context->builder->branchIf($noBound, $noBoundBb, $haveBoundBb);

        $context->builder->positionAtEnd($noBoundBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($haveBoundBb);
        $afterEq = $context->builder->inBoundsGEP($boundPos, $sizeT->constInt(9, false));
        $firstCh = $context->builder->load($afterEq);
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $firstCh, $i8->constInt(34, false));
        $quotedBb = $fn->appendBasicBlock('mp_llvm_v5_quoted');
        $bareBb = $fn->appendBasicBlock('mp_llvm_v5_bare');
        $afterBoundBb = $fn->appendBasicBlock('mp_llvm_v5_afterbound');
        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->branchIf($isQuote, $quotedBb, $bareBb);

        $context->builder->positionAtEnd($quotedBb);
        $qStart = $context->builder->inBoundsGEP($afterEq, $sizeT->constInt(1, false));
        $context->builder->store($qStart, $startSlot);
        $qEnd = $context->builder->call(
            $context->lookupFunction('strchr'),
            $qStart,
            $i32->constInt(34, false)
        );
        $qEndNull = $context->builder->icmp(Builder::INT_EQ, $qEnd, $i8p->constNull());
        $qBad = $fn->appendBasicBlock('mp_llvm_v5_qbad');
        $qOk = $fn->appendBasicBlock('mp_llvm_v5_qok');
        $context->builder->branchIf($qEndNull, $qBad, $qOk);
        $context->builder->positionAtEnd($qBad);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($qOk);
        $context->builder->store($qEnd, $endSlot);
        $context->builder->branch($afterBoundBb);

        $context->builder->positionAtEnd($bareBb);
        $context->builder->store($afterEq, $startSlot);
        $semi = $context->builder->call(
            $context->lookupFunction('strchr'),
            $afterEq,
            $i32->constInt(59, false)
        );
        $semiNull = $context->builder->icmp(Builder::INT_EQ, $semi, $i8p->constNull());
        $bareEndSemi = $fn->appendBasicBlock('mp_llvm_v5_bare_semi');
        $bareEndEos = $fn->appendBasicBlock('mp_llvm_v5_bare_eos');
        $context->builder->branchIf($semiNull, $bareEndEos, $bareEndSemi);
        $context->builder->positionAtEnd($bareEndSemi);
        $context->builder->store($semi, $endSlot);
        $context->builder->branch($afterBoundBb);
        $context->builder->positionAtEnd($bareEndEos);
        $eos = $context->builder->inBoundsGEP(
            $afterEq,
            $context->builder->call($context->lookupFunction('strlen'), $afterEq)
        );
        $context->builder->store($eos, $endSlot);
        $context->builder->branch($afterBoundBb);

        $context->builder->positionAtEnd($afterBoundBb);
        $bStart = $context->builder->load($startSlot);
        $bEnd = $context->builder->load($endSlot);
        // len = end - start (pointer subtract via ptrtoint)
        $startI = $context->builder->ptrToInt($bStart, $i64);
        $endI = $context->builder->ptrToInt($bEnd, $i64);
        $bLen64 = $context->builder->sub($endI, $startI);
        $bLen = $context->builder->truncOrBitCast($bLen64, $sizeT);
        $bLenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGT, $bLen, $sizeT->constInt(0, false)),
            $context->builder->icmp(Builder::INT_ULT, $bLen, $sizeT->constInt(self::BOUNDARY_BUF - 1, false))
        );
        $blenBad = $fn->appendBasicBlock('mp_llvm_v5_blen_bad');
        $blenOkBb = $fn->appendBasicBlock('mp_llvm_v5_blen_ok');
        $context->builder->branchIf($bLenOk, $blenOkBb, $blenBad);
        $context->builder->positionAtEnd($blenBad);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($blenOkBb);
        $context->builder->store($bLen, $boundaryLenSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($boundaryBuf, $i8p),
            $bStart,
            $bLen
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($boundaryBuf, $bLen)
        );

        // delim = "--" + boundary
        $context->builder->store($i8->constInt(45, false), $delimBuf);
        $context->builder->store(
            $i8->constInt(45, false),
            $context->builder->inBoundsGEP($delimBuf, $sizeT->constInt(1, false))
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast(
                $context->builder->inBoundsGEP($delimBuf, $sizeT->constInt(2, false)),
                $i8p
            ),
            $context->builder->pointerCast($boundaryBuf, $i8p),
            $bLen
        );
        $delimLen = $context->builder->add($bLen, $sizeT->constInt(2, false));
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($delimBuf, $delimLen)
        );
        $delimPtr = $context->builder->pointerCast($delimBuf, $i8p);

        $cursorSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $firstHit = self::callFind(
            $context,
            $body,
            $delimPtr
        );
        $noFirst = $context->builder->icmp(Builder::INT_EQ, $firstHit, $i8p->constNull());
        $noFirstBb = $fn->appendBasicBlock('mp_llvm_v5_nofirst');
        $loopInit = $fn->appendBasicBlock('mp_llvm_v5_loop_init');
        $context->builder->branchIf($noFirst, $noFirstBb, $loopInit);
        $context->builder->positionAtEnd($noFirstBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($loopInit);
        $afterFirst = $context->builder->inBoundsGEP($firstHit, $delimLen);
        $context->builder->store($afterFirst, $cursorSlot);
        $loopHead = $fn->appendBasicBlock('mp_llvm_v5_loop');
        $loopBody = $fn->appendBasicBlock('mp_llvm_v5_part');
        $done = $fn->appendBasicBlock('mp_llvm_v5_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $cursor = $context->builder->load($cursorSlot);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cursor, $i8p->constNull());
        $curEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($cursor),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($context->builder->or($curNull, $curEmpty), $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $cursor = $context->builder->load($cursorSlot);
        // Closing delimiter: part starts with "--"
        $dash0 = $context->builder->load($cursor);
        $dash1 = $context->builder->load(
            $context->builder->inBoundsGEP($cursor, $sizeT->constInt(1, false))
        );
        $isClose = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $dash0, $i8->constInt(45, false)),
            $context->builder->icmp(Builder::INT_EQ, $dash1, $i8->constInt(45, false))
        );
        $closeBb = $fn->appendBasicBlock('mp_llvm_v5_close');
        $skipCrLf = $fn->appendBasicBlock('mp_llvm_v5_skipcrlf');
        $context->builder->branchIf($isClose, $closeBb, $skipCrLf);
        $context->builder->positionAtEnd($closeBb);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skipCrLf);
        $cursor = $context->builder->load($cursorSlot);
        $c0 = $context->builder->load($cursor);
        $isCr = $context->builder->icmp(Builder::INT_EQ, $c0, $i8->constInt(13, false));
        $afterCrBb = $fn->appendBasicBlock('mp_llvm_v5_aftercr');
        $checkLfBb = $fn->appendBasicBlock('mp_llvm_v5_checklf');
        $partStartBb = $fn->appendBasicBlock('mp_llvm_v5_partstart');
        $context->builder->branchIf($isCr, $afterCrBb, $checkLfBb);
        $context->builder->positionAtEnd($afterCrBb);
        $context->builder->store(
            $context->builder->inBoundsGEP($cursor, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($checkLfBb);
        $context->builder->positionAtEnd($checkLfBb);
        $cursor = $context->builder->load($cursorSlot);
        $c0 = $context->builder->load($cursor);
        $isLf = $context->builder->icmp(Builder::INT_EQ, $c0, $i8->constInt(10, false));
        $afterLfBb = $fn->appendBasicBlock('mp_llvm_v5_afterlf');
        $context->builder->branchIf($isLf, $afterLfBb, $partStartBb);
        $context->builder->positionAtEnd($afterLfBb);
        $context->builder->store(
            $context->builder->inBoundsGEP($cursor, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($partStartBb);

        $context->builder->positionAtEnd($partStartBb);
        $partStart = $context->builder->load($cursorSlot);
        $nextDelim = self::callFind(
            $context,
            $partStart,
            $delimPtr
        );
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextDelim, $i8p->constNull());
        $noNextBb = $fn->appendBasicBlock('mp_llvm_v5_nonext');
        $haveNextBb = $fn->appendBasicBlock('mp_llvm_v5_havenext');
        $parsePartBb = $fn->appendBasicBlock('mp_llvm_v5_parsepart');
        $partEndSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->branchIf($nextNull, $noNextBb, $haveNextBb);

        $context->builder->positionAtEnd($noNextBb);
        $eos2 = $context->builder->inBoundsGEP(
            $partStart,
            $context->builder->call($context->lookupFunction('strlen'), $partStart)
        );
        $context->builder->store($eos2, $partEndSlot);
        $context->builder->branch($parsePartBb);

        $context->builder->positionAtEnd($haveNextBb);
        // Trim trailing \r\n before next delimiter
        $endAdj = $nextDelim;
        $endAdjSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($endAdj, $endAdjSlot);
        $trimBb = $fn->appendBasicBlock('mp_llvm_v5_trim');
        $context->builder->branch($trimBb);
        $context->builder->positionAtEnd($trimBb);
        $ea = $context->builder->load($endAdjSlot);
        $before = $context->builder->inBoundsGEP($ea, $i64->constInt(-1, true));
        $beforeOk = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($before, $i64),
            $context->builder->ptrToInt($partStart, $i64)
        );
        $ch = $context->builder->load($before);
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false))
        );
        $doTrim = $context->builder->and($beforeOk, $isWs);
        $trimYes = $fn->appendBasicBlock('mp_llvm_v5_trim_yes');
        $trimDone = $fn->appendBasicBlock('mp_llvm_v5_trim_done');
        $context->builder->branchIf($doTrim, $trimYes, $trimDone);
        $context->builder->positionAtEnd($trimYes);
        $context->builder->store($before, $endAdjSlot);
        $context->builder->branch($trimBb);
        $context->builder->positionAtEnd($trimDone);
        $context->builder->store($context->builder->load($endAdjSlot), $partEndSlot);
        $context->builder->branch($parsePartBb);

        $context->builder->positionAtEnd($parsePartBb);
        $partStart = $context->builder->load($cursorSlot);
        $partEnd = $context->builder->load($partEndSlot);
        $hdrSep = self::callFind(
            $context,
            $partStart,
            $context->pointerFromStringConstant("\r\n\r\n")
        );
        $sepNull = $context->builder->icmp(Builder::INT_EQ, $hdrSep, $i8p->constNull());
        // Also try \n\n for normalized bodies
        $tryLfBb = $fn->appendBasicBlock('mp_llvm_v5_trylf');
        $haveSepBb = $fn->appendBasicBlock('mp_llvm_v5_havesep');
        $skipPartBb = $fn->appendBasicBlock('mp_llvm_v5_skippart');
        $context->builder->branchIf($sepNull, $tryLfBb, $haveSepBb);

        $context->builder->positionAtEnd($tryLfBb);
        $hdrSep2 = self::callFind(
            $context,
            $partStart,
            $context->pointerFromStringConstant("\n\n")
        );
        $sep2Null = $context->builder->icmp(Builder::INT_EQ, $hdrSep2, $i8p->constNull());
        $sep2Ok = $fn->appendBasicBlock('mp_llvm_v5_sep2ok');
        $context->builder->branchIf($sep2Null, $skipPartBb, $sep2Ok);
        $context->builder->positionAtEnd($sep2Ok);
        $contentStart2 = $context->builder->inBoundsGEP($hdrSep2, $sizeT->constInt(2, false));
        $nameNeedle = $context->pointerFromStringConstant('; name="');
        $namePos2 = self::callFind(
            $context,
            $partStart,
            $nameNeedle
        );
        self::emitHandlePart(
            $context,
            $fn,
            $post,
            $files,
            $partStart,
            $hdrSep2,
            $contentStart2,
            $partEnd,
            $namePos2,
            $skipPartBb,
            'lf'
        );

        $context->builder->positionAtEnd($haveSepBb);
        $contentStart = $context->builder->inBoundsGEP($hdrSep, $sizeT->constInt(4, false));
        $namePos = self::callFind(
            $context,
            $partStart,
            $context->pointerFromStringConstant('; name="')
        );
        self::emitHandlePart(
            $context,
            $fn,
            $post,
            $files,
            $partStart,
            $hdrSep,
            $contentStart,
            $partEnd,
            $namePos,
            $skipPartBb,
            'crlf'
        );

        $context->builder->positionAtEnd($skipPartBb);
        $nextDelim2 = self::callFind(
            $context,
            $context->builder->load($cursorSlot),
            $delimPtr
        );
        $ndNull = $context->builder->icmp(Builder::INT_EQ, $nextDelim2, $i8p->constNull());
        $advDone = $fn->appendBasicBlock('mp_llvm_v5_adv_done');
        $advNext = $fn->appendBasicBlock('mp_llvm_v5_adv_next');
        $context->builder->branchIf($ndNull, $advDone, $advNext);
        $context->builder->positionAtEnd($advDone);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($advNext);
        $context->builder->store(
            $context->builder->inBoundsGEP($nextDelim2, $delimLen),
            $cursorSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();

        $context->registerFunction(self::PARSE_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitHandlePart(
        Context $context,
        LlvmFunction $fn,
        Value $post,
        Value $files,
        Value $partStart,
        Value $hdrSep,
        Value $contentStart,
        Value $partEnd,
        Value $namePos,
        \PHPLLVM\BasicBlock $skipPartBb,
        string $tag
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        $nameNull = $context->builder->icmp(Builder::INT_EQ, $namePos, $i8p->constNull());
        // name= must appear before header/body separator
        $nameBeforeSep = $context->builder->icmp(
            Builder::INT_ULT,
            $context->builder->ptrToInt($namePos, $i64),
            $context->builder->ptrToInt($hdrSep, $i64)
        );
        $nameOk = $context->builder->and(
            $context->builder->not($nameNull),
            $nameBeforeSep
        );
        $noName = $fn->appendBasicBlock('mp_llvm_v5_noname_'.$tag);
        $haveName = $fn->appendBasicBlock('mp_llvm_v5_havename_'.$tag);
        $context->builder->branchIf($nameOk, $haveName, $noName);
        $context->builder->positionAtEnd($noName);
        $context->builder->branch($skipPartBb);

        $context->builder->positionAtEnd($haveName);
        $nameStart = $context->builder->inBoundsGEP($namePos, $sizeT->constInt(8, false));
        $nameEnd = $context->builder->call(
            $context->lookupFunction('strchr'),
            $nameStart,
            $i32->constInt(34, false)
        );
        $neNull = $context->builder->icmp(Builder::INT_EQ, $nameEnd, $i8p->constNull());
        $neBad = $fn->appendBasicBlock('mp_llvm_v5_nebad_'.$tag);
        $neOk = $fn->appendBasicBlock('mp_llvm_v5_neok_'.$tag);
        $context->builder->branchIf($neNull, $neBad, $neOk);
        $context->builder->positionAtEnd($neBad);
        $context->builder->branch($skipPartBb);

        $context->builder->positionAtEnd($neOk);
        $nameLen = $context->builder->truncOrBitCast(
            $context->builder->sub(
                $context->builder->ptrToInt($nameEnd, $i64),
                $context->builder->ptrToInt($nameStart, $i64)
            ),
            $sizeT
        );
        $fnPos = self::callFind(
            $context,
            $partStart,
            $context->pointerFromStringConstant('filename="')
        );
        $fnNull = $context->builder->icmp(Builder::INT_EQ, $fnPos, $i8p->constNull());
        $fnBefore = $context->builder->icmp(
            Builder::INT_ULT,
            $context->builder->ptrToInt($fnPos, $i64),
            $context->builder->ptrToInt($hdrSep, $i64)
        );
        $isFile = $context->builder->and($context->builder->not($fnNull), $fnBefore);
        $fileBb = $fn->appendBasicBlock('mp_llvm_v5_file_'.$tag);
        $fieldBb = $fn->appendBasicBlock('mp_llvm_v5_field_'.$tag);
        $context->builder->branchIf($isFile, $fileBb, $fieldBb);

        $context->builder->positionAtEnd($fieldBb);
        $contentLen = $context->builder->truncOrBitCast(
            $context->builder->sub(
                $context->builder->ptrToInt($partEnd, $i64),
                $context->builder->ptrToInt($contentStart, $i64)
            ),
            $sizeT
        );
        self::setStringKeySlice($context, $post, $nameStart, $nameLen, $contentStart, $contentLen);
        $context->builder->branch($skipPartBb);

        $context->builder->positionAtEnd($fileBb);
        $fnStart = $context->builder->inBoundsGEP($fnPos, $sizeT->constInt(10, false));
        $fnEnd = $context->builder->call(
            $context->lookupFunction('strchr'),
            $fnStart,
            $i32->constInt(34, false)
        );
        $feNull = $context->builder->icmp(Builder::INT_EQ, $fnEnd, $i8p->constNull());
        $feBad = $fn->appendBasicBlock('mp_llvm_v5_febad_'.$tag);
        $feOk = $fn->appendBasicBlock('mp_llvm_v5_feok_'.$tag);
        $context->builder->branchIf($feNull, $feBad, $feOk);
        $context->builder->positionAtEnd($feBad);
        $context->builder->branch($skipPartBb);

        $context->builder->positionAtEnd($feOk);
        $fnLen = $context->builder->truncOrBitCast(
            $context->builder->sub(
                $context->builder->ptrToInt($fnEnd, $i64),
                $context->builder->ptrToInt($fnStart, $i64)
            ),
            $sizeT
        );
        $contentLen = $context->builder->truncOrBitCast(
            $context->builder->sub(
                $context->builder->ptrToInt($partEnd, $i64),
                $context->builder->ptrToInt($contentStart, $i64)
            ),
            $sizeT
        );

        $entryHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $entryHt,
            self::cstrToString($context, $context->pointerFromStringConstant('name')),
            self::cstrSliceToString($context, $fnStart, $fnLen)
        );

        // Default application/octet-stream; upgrade to text/plain when header contains it.
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('type'),
            $context->pointerFromStringConstant('application/octet-stream')
        );
        $plainHit = self::callFind(
            $context,
            $partStart,
            $context->pointerFromStringConstant('text/plain')
        );
        $plainOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $plainHit, $i8p->constNull()),
            $context->builder->icmp(
                Builder::INT_ULT,
                $context->builder->ptrToInt($plainHit, $i64),
                $context->builder->ptrToInt($hdrSep, $i64)
            )
        );
        $plainYes = $fn->appendBasicBlock('mp_llvm_v5_plain_'.$tag);
        $writeBb = $fn->appendBasicBlock('mp_llvm_v5_write_'.$tag);
        $context->builder->branchIf($plainOk, $plainYes, $writeBb);
        $context->builder->positionAtEnd($plainYes);
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('type'),
            $context->pointerFromStringConstant('text/plain')
        );
        $context->builder->branch($writeBb);

        $context->builder->positionAtEnd($writeBb);
        $path = $context->pointerFromStringConstant(self::FIXED_UPLOAD_PATH);
        $fp = $context->builder->call(
            $context->lookupFunction('fopen'),
            $path,
            $context->pointerFromStringConstant('wb')
        );
        $fpOk = $context->builder->icmp(Builder::INT_NE, $fp, $i8p->constNull());
        $fpYes = $fn->appendBasicBlock('mp_llvm_v5_fpy_'.$tag);
        $fpNo = $fn->appendBasicBlock('mp_llvm_v5_fpn_'.$tag);
        $fileDone = $fn->appendBasicBlock('mp_llvm_v5_filedone_'.$tag);
        $context->builder->branchIf($fpOk, $fpYes, $fpNo);

        $context->builder->positionAtEnd($fpNo);
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('error'),
            $context->pointerFromStringConstant('1')
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $files,
            self::cstrSliceToString($context, $nameStart, $nameLen),
            $entryHt
        );
        $context->builder->branch($fileDone);

        $context->builder->positionAtEnd($fpYes);
        $context->builder->call(
            $context->lookupFunction('fwrite'),
            $contentStart,
            $sizeT->constInt(1, false),
            $contentLen,
            $fp
        );
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('tmp_name'),
            $path
        );
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('error'),
            $context->pointerFromStringConstant('0')
        );
        // size as decimal string via snprintf into small alloca
        $sizeBuf = $context->builder->alloca($i8, 32, 'mp_size_'.$tag);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->builder->pointerCast($sizeBuf, $i8p),
            $sizeT->constInt(32, false),
            $context->pointerFromStringConstant('%zu'),
            $contentLen
        );
        self::setStringKeyCstr(
            $context,
            $entryHt,
            $context->pointerFromStringConstant('size'),
            $context->builder->pointerCast($sizeBuf, $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $files,
            self::cstrSliceToString($context, $nameStart, $nameLen),
            $entryHt
        );
        $context->builder->branch($fileDone);

        $context->builder->positionAtEnd($fileDone);
        $context->builder->branch($skipPartBb);
    }
}
