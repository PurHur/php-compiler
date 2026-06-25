<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for __compiler_date_interval_format (#9499, #11518).
 *
 * Embed/MCJIT routes through {@see DateIntervalFormatJitHelper} via {@see DateIntervalFormatRuntime}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
 */
final class DateIntervalFormatStandaloneLlvm
{
    private const ABI_NAME = '__compiler_date_interval_format';

    private const OUT_BYTES = 128;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        StringFormatJit::implement($context);

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);
        self::implementFormat($context, $fn);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFormatBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('di_fmt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::FORMAT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4),
            $fn->getParam(5),
            $fn->getParam(6),
            $fn->getParam(7),
            $fn->getParam(8),
            $fn->getParam(9),
            $fn->getParam(10)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after DateIntervalFormatJitHelper compile (#9499)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'DateIntervalFormatJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('DateIntervalFormatJitHelper.php parseAndCompile failed (#9499)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9499)');
            }
        }
    }

    private static function implementFormat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('di_fmt_entry');
        $context->builder->positionAtEnd($entry);

        $y = $fn->getParam(0);
        $m = $fn->getParam(1);
        $d = $fn->getParam(2);
        $h = $fn->getParam(3);
        $i = $fn->getParam(4);
        $s = $fn->getParam(5);
        $f = $fn->getParam(6);
        $invert = $fn->getParam(7);
        $daysIsInt = $fn->getParam(8);
        $daysInt = $fn->getParam(9);
        $format = $fn->getParam(10);

        $strMap = $context->structFieldMap['__string__'];
        $fmtLen = $context->builder->load($context->builder->structGep($format, $strMap['length']));
        $fmtChars = $context->builder->structGep($format, $strMap['value']);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $ten = $i64->constInt(10, false);
        $million = $i64->constInt(1_000_000, false);
        $cap = $sizeT->constInt(self::OUT_BYTES, false);

        $outLenSlot = $context->builder->alloca($sizeT, 1, 'di_out_len');
        $context->builder->store($sizeT->constInt(0, false), $outLenSlot);
        $outLenPtr = $context->builder->pointerCast($outLenSlot, $sizeTp);
        $outBuf = $context->builder->alloca($i8, self::OUT_BYTES, 'di_out_buf');
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $iSlot = $context->builder->alloca($i64, 1, 'di_fmt_i');
        $context->builder->store($zero, $iSlot);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendDecimal = $context->lookupFunction('__phpc_fmt_append_decimal_ll');
        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');

        $head = $fn->appendBasicBlock('di_fmt_head');
        $body = $fn->appendBasicBlock('di_fmt_body');
        $done = $fn->appendBasicBlock('di_fmt_done');
        $emit = $fn->appendBasicBlock('di_fmt_emit');
        $after = $fn->appendBasicBlock('di_fmt_after');
        $percentOnly = $fn->appendBasicBlock('di_fmt_percent_only');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $fmtLen);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($fmtChars, $idx));
        $chI32 = $context->builder->zExt($ch, $i32);
        $isPercent = $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(0x25, false));
        $plain = $fn->appendBasicBlock('di_fmt_plain');
        $context->builder->branchIf($isPercent, $emit, $plain);

        $context->builder->positionAtEnd($plain);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $ch);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($emit);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $nextIdx, $fmtLen);
        $codeBlock = $fn->appendBasicBlock('di_fmt_code');
        $context->builder->branchIf($atEnd, $percentOnly, $codeBlock);
        $context->builder->positionAtEnd($codeBlock);
        $codeCh = $context->builder->load($context->builder->gep($fmtChars, $nextIdx));
        $codeI32 = $context->builder->zExt($codeCh, $i32);
        self::emitFormatCode(
            $context,
            $fn,
            $codeI32,
            $outPtr,
            $outLenPtr,
            $cap,
            $appendChar,
            $appendDecimal,
            $appendStr,
            $y,
            $m,
            $d,
            $h,
            $i,
            $s,
            $f,
            $invert,
            $daysIsInt,
            $daysInt,
            $zero,
            $ten,
            $million
        );
        $context->builder->store($context->builder->addNoSignedWrap($nextIdx, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($percentOnly);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $ch);
        $context->builder->store($nextIdx, $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($after);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->zExt($context->builder->load($outLenSlot), $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $outPtr
        );
        $context->builder->returnValue($result);
    }

    private static function emitFormatCode(
        Context $context,
        LlvmFunction $fn,
        Value $codeI32,
        Value $outPtr,
        Value $outLenPtr,
        Value $cap,
        Value $appendChar,
        Value $appendDecimal,
        Value $appendStr,
        Value $y,
        Value $m,
        Value $d,
        Value $h,
        Value $i,
        Value $s,
        Value $f,
        Value $invert,
        Value $daysIsInt,
        Value $daysInt,
        Value $zero,
        Value $ten,
        Value $million
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $done = $fn->appendBasicBlock('di_code_done');
        $defaultBlock = $fn->appendBasicBlock('di_code_default');

        $codes = [
            ord('y') => ['val' => $y, 'pad' => false],
            ord('Y') => ['val' => $y, 'pad' => true],
            ord('m') => ['val' => $m, 'pad' => false],
            ord('M') => ['val' => $m, 'pad' => true],
            ord('d') => ['val' => $d, 'pad' => false],
            ord('D') => ['val' => $d, 'pad' => true],
            ord('h') => ['val' => $h, 'pad' => false],
            ord('H') => ['val' => $h, 'pad' => true],
            ord('i') => ['val' => $i, 'pad' => false],
            ord('I') => ['val' => $i, 'pad' => true],
            ord('s') => ['val' => $s, 'pad' => false],
            ord('S') => ['val' => $s, 'pad' => true],
        ];

        $checkBlock = $context->builder->getInsertBlock();
        foreach ($codes as $ord => $spec) {
            $matchBlock = $fn->appendBasicBlock('di_code_'.$ord);
            $nextBlock = $fn->appendBasicBlock('di_code_try_'.$ord);
            $context->builder->positionAtEnd($checkBlock);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt($ord, false));
            $context->builder->branchIf($isMatch, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            if ($spec['pad']) {
                self::appendPadded2($context, $fn, $outPtr, $outLenPtr, $cap, $appendChar, $appendDecimal, $spec['val'], $ten);
            } else {
                $context->builder->call($appendDecimal, $outPtr, $outLenPtr, $cap, $spec['val']);
            }
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }

        $context->builder->positionAtEnd($checkBlock);

        $fBlock = $fn->appendBasicBlock('di_code_f');
        $afterF = $fn->appendBasicBlock('di_code_after_f');
        $isF = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt(ord('f'), false));
        $context->builder->branchIf($isF, $fBlock, $afterF);
        $context->builder->positionAtEnd($fBlock);
        $micro = $context->builder->fptosi(
            $context->builder->fmul($f, $context->builder->sitofp($million, $dbl)),
            $i64
        );
        $context->builder->call($appendDecimal, $outPtr, $outLenPtr, $cap, $micro);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterF);

        $aBlock = $fn->appendBasicBlock('di_code_a');
        $afterA = $fn->appendBasicBlock('di_code_after_a');
        $isA = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt(ord('a'), false));
        $context->builder->branchIf($isA, $aBlock, $afterA);
        $context->builder->positionAtEnd($aBlock);
        $hasDays = $context->builder->icmp(Builder::INT_NE, $daysIsInt, $zero);
        $daysIntBlock = $fn->appendBasicBlock('di_code_a_int');
        $daysUnknownBlock = $fn->appendBasicBlock('di_code_a_unknown');
        $context->builder->branchIf($hasDays, $daysIntBlock, $daysUnknownBlock);
        $context->builder->positionAtEnd($daysIntBlock);
        $context->builder->call($appendDecimal, $outPtr, $outLenPtr, $cap, $daysInt);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($daysUnknownBlock);
        $unknownStr = $context->builder->load($context->constantStringFromString('(unknown)'));
        $unknownData = $context->builder->structGep($unknownStr, $strMap['value']);
        $unknownLen = $context->builder->load($context->builder->structGep($unknownStr, $strMap['length']));
        $unknownPtr = $context->builder->pointerCast($unknownData, $i8p);
        $unknownLenSt = $context->builder->zExt($unknownLen, $sizeT);
        $context->builder->call($appendStr, $outPtr, $outLenPtr, $cap, $unknownPtr, $unknownLenSt);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterA);

        $rBlock = $fn->appendBasicBlock('di_code_R');
        $afterR = $fn->appendBasicBlock('di_code_after_R');
        $isR = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt(ord('R'), false));
        $context->builder->branchIf($isR, $rBlock, $afterR);
        $context->builder->positionAtEnd($rBlock);
        $neg = $context->builder->icmp(Builder::INT_NE, $invert, $zero);
        $signChar = $context->builder->select(
            $neg,
            $i8->constInt(ord('-'), false),
            $i8->constInt(ord('+'), false)
        );
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $signChar);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterR);

        $rLowerBlock = $fn->appendBasicBlock('di_code_r');
        $afterRLower = $fn->appendBasicBlock('di_code_after_r');
        $isRLower = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt(ord('r'), false));
        $context->builder->branchIf($isRLower, $rLowerBlock, $afterRLower);
        $context->builder->positionAtEnd($rLowerBlock);
        $negR = $context->builder->icmp(Builder::INT_NE, $invert, $zero);
        $rMinusBlock = $fn->appendBasicBlock('di_code_r_minus');
        $rSkipBlock = $fn->appendBasicBlock('di_code_r_skip');
        $context->builder->branchIf($negR, $rMinusBlock, $rSkipBlock);
        $context->builder->positionAtEnd($rMinusBlock);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $i8->constInt(ord('-'), false));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($rSkipBlock);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterRLower);

        $pctBlock = $fn->appendBasicBlock('di_code_pct');
        $afterPct = $fn->appendBasicBlock('di_code_after_pct');
        $isPct = $context->builder->icmp(Builder::INT_EQ, $codeI32, $i32->constInt(ord('%'), false));
        $context->builder->branchIf($isPct, $pctBlock, $afterPct);
        $context->builder->positionAtEnd($pctBlock);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $i8->constInt(ord('%'), false));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterPct);

        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $i8->constInt(ord('%'), false));
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $context->builder->trunc($codeI32, $i8));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function appendPadded2(
        Context $context,
        LlvmFunction $fn,
        Value $outPtr,
        Value $outLenPtr,
        Value $cap,
        Value $appendChar,
        Value $appendDecimal,
        Value $val,
        Value $ten
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $ltTen = $context->builder->icmp(Builder::INT_SLT, $val, $ten);
        $padBlock = $fn->appendBasicBlock('di_pad');
        $noPadBlock = $fn->appendBasicBlock('di_no_pad');
        $doneBlock = $fn->appendBasicBlock('di_pad_done');
        $context->builder->branchIf($ltTen, $padBlock, $noPadBlock);
        $context->builder->positionAtEnd($padBlock);
        $context->builder->call($appendChar, $outPtr, $outLenPtr, $cap, $i8->constInt(ord('0'), false));
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($noPadBlock);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->call($appendDecimal, $outPtr, $outLenPtr, $cap, $val);
    }

}
