<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_fgetcsv (mirrors VmFs::fgetcsv + VmCsv::parseLine, issue #6750).
 *
 * Stream read uses thin C ABI {@see __phpc_resolve_stream}; CSV parse uses
 * {@see CsvJitHelper} PHP on JIT modules or {@see StringStrGetcsvJit} LLVM on standalone (#9444).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fgetcsv)
 */
final class StringFgetcsvJit
{
    private const DEFAULT_BUF = 8192;

    private const PARSE_LINE_HELPER = 'PHPCompiler\\ext\\standard\\CsvJitHelper::parseLineArgv';

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        StringStrGetcsv::implement($context);
        self::ensureLibc($context);
        self::ensureStreamResolve($context);

        $probe = $context->module->getNamedFunction('__compiler_fgetcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_fgetcsv', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        try {
            $fn = $context->lookupFunction('__compiler_fgetcsv');
        } catch (\Throwable) {
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $strPtr = $context->getTypeFromString('__string__*');
            $i64 = $context->getTypeFromString('int64');
            $fn = $context->module->addFunction(
                '__compiler_fgetcsv',
                $context->context->functionType($htPtr, false, $i64, $i64, $strPtr, $strPtr, $strPtr)
            );
            $context->registerFunction('__compiler_fgetcsv', $fn);
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitCompilerFgetcsv($context, $fn);
        } else {
            self::emitCompilerFgetcsvPhpParse($context, $fn);
        }
        $context->registerFunction('__compiler_fgetcsv', $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        foreach ([
            ['malloc', $voidPtr, [$sizeT]],
            ['free', $voidTy, [$i8p]],
            ['fgets', $i8p, [$i8p, $i32, $voidPtr]],
            ['strlen', $sizeT, [$i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function ensureStreamResolve(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $i64 = $context->getTypeFromString('int64');

        try {
            $context->lookupFunction('__phpc_resolve_stream');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__phpc_resolve_stream',
                $context->context->functionType($voidPtr, false, $i64)
            );
            $context->registerFunction('__phpc_resolve_stream', $fn);
        }
    }

    private static function emitCompilerFgetcsv(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $defaultBuf = $sizeT->constInt(self::DEFAULT_BUF, false);
        $nl = $i8->constInt(ord("\n"), false);
        $cr = $i8->constInt(ord("\r"), false);
        $nullChar = $i8->constInt(0, false);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $separator = $fn->getParam(2);
        $enclosure = $fn->getParam(3);
        $escape = $fn->getParam(4);

        $fp = $context->builder->call(
            $context->lookupFunction('__phpc_resolve_stream'),
            $handle
        );
        $noFpBb = $fn->appendBasicBlock('fgetcsv_no_fp');
        $lenBb = $fn->appendBasicBlock('fgetcsv_len');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $voidPtr->constNull()),
            $noFpBb,
            $lenBb
        );

        $context->builder->positionAtEnd($noFpBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($lenBb);
        $lenNotPositive = $context->builder->icmp(Builder::INT_SLE, $length, $zero64);
        $allocBb = $fn->appendBasicBlock('fgetcsv_alloc');
        $context->builder->branch($allocBb);

        $context->builder->positionAtEnd($allocBb);
        $bufSize = $context->builder->select(
            $lenNotPositive,
            $defaultBuf,
            $context->builder->truncOrBitCast($length, $sizeT)
        );
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufSize);
        $noBufBb = $fn->appendBasicBlock('fgetcsv_no_buf');
        $readBb = $fn->appendBasicBlock('fgetcsv_read');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $buf, $voidPtr->constNull()),
            $noBufBb,
            $readBb
        );

        $context->builder->positionAtEnd($noBufBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($readBb);
        $bufI8 = $context->builder->pointerCast($buf, $i8p);
        $line = $context->builder->call(
            $context->lookupFunction('fgets'),
            $bufI8,
            $context->builder->truncOrBitCast($bufSize, $i32),
            $fp
        );
        $eofBb = $fn->appendBasicBlock('fgetcsv_eof');
        $stripBb = $fn->appendBasicBlock('fgetcsv_strip');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $line, $i8p->constNull()),
            $eofBb,
            $stripBb
        );

        $context->builder->positionAtEnd($eofBb);
        $context->builder->call($context->lookupFunction('free'), $bufI8);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($stripBb);
        $n = $context->builder->call($context->lookupFunction('strlen'), $bufI8);
        $stripLoopBb = $fn->appendBasicBlock('fgetcsv_strip_loop');
        $stripDoneBb = $fn->appendBasicBlock('fgetcsv_strip_done');
        $context->builder->branch($stripLoopBb);

        $context->builder->positionAtEnd($stripLoopBb);
        $nPhi = $context->builder->phi($sizeT, [[$n, $stripBb]]);
        $hasTail = $context->builder->icmp(Builder::INT_UGT, $nPhi, $zero64);
        $checkTailBb = $fn->appendBasicBlock('fgetcsv_strip_check');
        $context->builder->branchIf($hasTail, $checkTailBb, $stripDoneBb);

        $context->builder->positionAtEnd($checkTailBb);
        $lastIdx = $context->builder->sub($nPhi, $one);
        $lastByte = $context->builder->load($context->builder->gep($bufI8, $lastIdx));
        $isNl = $context->builder->icmp(Builder::INT_EQ, $lastByte, $nl);
        $isCr = $context->builder->icmp(Builder::INT_EQ, $lastByte, $cr);
        $isEol = $context->builder->or($isNl, $isCr);
        $trimBb = $fn->appendBasicBlock('fgetcsv_strip_trim');
        $context->builder->branchIf($isEol, $trimBb, $stripDoneBb);

        $context->builder->positionAtEnd($trimBb);
        $context->builder->store($nullChar, $context->builder->gep($bufI8, $lastIdx));
        $newN = $context->builder->sub($nPhi, $one);
        $nPhi->addIncoming($newN, $trimBb);
        $context->builder->branch($stripLoopBb);

        $context->builder->positionAtEnd($stripDoneBb);
        $lineLen = $context->builder->phi($sizeT, [
            [$nPhi, $stripLoopBb],
            [$nPhi, $checkTailBb],
        ]);

        $delim = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $separator,
            $i8->constInt(ord(','), false)
        );
        $enc = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $enclosure,
            $i8->constInt(ord('"'), false)
        );
        $esc = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $escape,
            $i8->constInt(ord('\\'), false)
        );
        $result = $context->builder->call(
            $context->lookupFunction('__phpc_csv_parse_line'),
            $bufI8,
            $lineLen,
            $delim,
            $enc,
            $esc
        );
        $context->builder->call($context->lookupFunction('free'), $bufI8);
        $context->builder->returnValue($result);
    }

    private static function emitCompilerFgetcsvPhpParse(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $defaultBuf = $sizeT->constInt(self::DEFAULT_BUF, false);
        $nl = $i8->constInt(ord("\n"), false);
        $cr = $i8->constInt(ord("\r"), false);
        $nullChar = $i8->constInt(0, false);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $separator = $fn->getParam(2);
        $enclosure = $fn->getParam(3);
        $escape = $fn->getParam(4);

        $fp = $context->builder->call(
            $context->lookupFunction('__phpc_resolve_stream'),
            $handle
        );
        $noFpBb = $fn->appendBasicBlock('fgetcsv_no_fp');
        $lenBb = $fn->appendBasicBlock('fgetcsv_len');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $voidPtr->constNull()),
            $noFpBb,
            $lenBb
        );

        $context->builder->positionAtEnd($noFpBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($lenBb);
        $lenNotPositive = $context->builder->icmp(Builder::INT_SLE, $length, $zero64);
        $allocBb = $fn->appendBasicBlock('fgetcsv_alloc');
        $context->builder->branch($allocBb);

        $context->builder->positionAtEnd($allocBb);
        $bufSize = $context->builder->select(
            $lenNotPositive,
            $defaultBuf,
            $context->builder->truncOrBitCast($length, $sizeT)
        );
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufSize);
        $noBufBb = $fn->appendBasicBlock('fgetcsv_no_buf');
        $readBb = $fn->appendBasicBlock('fgetcsv_read');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $buf, $voidPtr->constNull()),
            $noBufBb,
            $readBb
        );

        $context->builder->positionAtEnd($noBufBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($readBb);
        $bufI8 = $context->builder->pointerCast($buf, $i8p);
        $line = $context->builder->call(
            $context->lookupFunction('fgets'),
            $bufI8,
            $context->builder->truncOrBitCast($bufSize, $i32),
            $fp
        );
        $eofBb = $fn->appendBasicBlock('fgetcsv_eof');
        $stripBb = $fn->appendBasicBlock('fgetcsv_strip');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $line, $i8p->constNull()),
            $eofBb,
            $stripBb
        );

        $context->builder->positionAtEnd($eofBb);
        $context->builder->call($context->lookupFunction('free'), $bufI8);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($stripBb);
        $n = $context->builder->call($context->lookupFunction('strlen'), $bufI8);
        $stripLoopBb = $fn->appendBasicBlock('fgetcsv_strip_loop');
        $stripDoneBb = $fn->appendBasicBlock('fgetcsv_strip_done');
        $context->builder->branch($stripLoopBb);

        $context->builder->positionAtEnd($stripLoopBb);
        $nPhi = $context->builder->phi($sizeT, [[$n, $stripBb]]);
        $hasTail = $context->builder->icmp(Builder::INT_UGT, $nPhi, $zero64);
        $checkTailBb = $fn->appendBasicBlock('fgetcsv_strip_check');
        $context->builder->branchIf($hasTail, $checkTailBb, $stripDoneBb);

        $context->builder->positionAtEnd($checkTailBb);
        $lastIdx = $context->builder->sub($nPhi, $one);
        $lastByte = $context->builder->load($context->builder->gep($bufI8, $lastIdx));
        $isNl = $context->builder->icmp(Builder::INT_EQ, $lastByte, $nl);
        $isCr = $context->builder->icmp(Builder::INT_EQ, $lastByte, $cr);
        $isEol = $context->builder->or($isNl, $isCr);
        $trimBb = $fn->appendBasicBlock('fgetcsv_strip_trim');
        $context->builder->branchIf($isEol, $trimBb, $stripDoneBb);

        $context->builder->positionAtEnd($trimBb);
        $context->builder->store($nullChar, $context->builder->gep($bufI8, $lastIdx));
        $newN = $context->builder->sub($nPhi, $one);
        $nPhi->addIncoming($newN, $trimBb);
        $context->builder->branch($stripLoopBb);

        $context->builder->positionAtEnd($stripDoneBb);
        $lineLen = $context->builder->phi($sizeT, [
            [$nPhi, $stripLoopBb],
            [$nPhi, $checkTailBb],
        ]);

        $lineLenI64 = $context->builder->truncOrBitCast($lineLen, $i64);
        $lineStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lineLenI64,
            $bufI8
        );
        $sepSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $separator, ',');
        $encSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $enclosure, '"');
        $escSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $escape, '\\');
        $lineSep = $context->builder->call($context->lookupFunction('__string__separate'), $lineStr);
        $result = $context->builder->call(
            self::parseLineHelper($context),
            $lineSep,
            $sepSep,
            $encSep,
            $escSep
        );
        $context->builder->call($context->lookupFunction('free'), $bufI8);
        $context->builder->returnValue($result);
    }

    private static function parseLineHelper(Context $context): LlvmFunction
    {
        $lc = \strtolower(self::PARSE_LINE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::PARSE_LINE_HELPER.' missing after CsvJitHelper compile (#9444)');
        }

        return $fn;
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
