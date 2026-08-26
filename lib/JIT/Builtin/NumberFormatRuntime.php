<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * number_format() via snprintf + LLVM thousands grouping (#31963).
 *
 * Bypasses NestedJIT `(string)$n` concat SIGSEGV. php-src: ext/standard/math.c
 *
 * #35056: PHP_ROUND_HALF_UP pre-round (not libc half-even) + apply $decimal_separator
 * after thousands grouping (scan still keys off '.').
 */
final class NumberFormatRuntime
{
    private const BUF_SIZE = 128;

    private static int $seq = 0;

    /**
     * Emit bridge body; ends with returnValue in $fn.
     *
     * @param Value $decOrd  first byte of decimal_separator (0 = empty → strip '.')
     * @param Value $thouOrd first byte of thousands_separator (0 = skip grouping)
     */
    public static function emitBridgeBody(
        Context $context,
        LlvmFunction $fn,
        Value $decOrd,
        Value $thouOrd
    ): void {
        self::ensureDecls($context);
        ++self::$seq;
        $s = (string) self::$seq;

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $number = $fn->getParam(0);
        $decimals = $fn->getParam(1);

        $decI32 = $context->builder->trunc($decimals, $i32);
        $negDec = $context->builder->icmp(Builder::INT_SLT, $decI32, $i32->constInt(0, true));
        $decI32 = $context->builder->select(
            $negDec,
            $i32->constInt(0, true),
            $decI32
        );
        $decI64 = $context->builder->zExt($decI32, $i64);

        // php-src _php_math_number_format_ex: PHP_ROUND_HALF_UP before formatting (#35056).
        $number = self::halfUpRound($context, $fn, $number, $decI64, $s);

        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString('%.*f'),
            $charPtr
        );
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(self::BUF_SIZE, false),
            $fmtPtr,
            $decI32,
            $number
        );
        $rawLen = $context->builder->zExt($written, $i64);
        $rawStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $rawLen,
            $context->builder->pointerCast($buf, $i8p)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        $noSepBb = $fn->appendBasicBlock('nf_no_sep_'.$s);
        $groupBb = $fn->appendBasicBlock('nf_group_'.$s);
        $afterGroupBb = $fn->appendBasicBlock('nf_after_group_'.$s);
        $hasSep = $context->builder->icmp(Builder::INT_UGT, $thouOrd, $i64->constInt(0, false));
        $context->builder->branchIf($hasSep, $groupBb, $noSepBb);

        $context->builder->positionAtEnd($noSepBb);
        $context->builder->branch($afterGroupBb);

        $context->builder->positionAtEnd($groupBb);
        $grouped = self::insertThousands($context, $fn, $rawStr, $thouOrd, $s);
        $groupEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterGroupBb);

        $context->builder->positionAtEnd($afterGroupBb);
        $groupedPhi = $context->builder->phi($strPtr);
        $groupedPhi->addIncoming($rawStr, $noSepBb);
        $groupedPhi->addIncoming($grouped, $groupEnd);

        // Apply decimal_separator after thousands scan (which keys off '.') (#35056).
        $final = self::applyDecimalSeparator($context, $fn, $groupedPhi, $decOrd, $decI64, $s);
        $context->builder->returnValue($final);
    }

    /**
     * PHP_ROUND_HALF_UP at $decimals places (php-src math.c / SprintfJitHelper::numberFormat).
     * Uses libm floor(3): abs*scale+0.5 then floor, restore sign.
     */
    private static function halfUpRound(
        Context $context,
        LlvmFunction $fn,
        Value $number,
        Value $decimals,
        string $s
    ): Value {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        self::ensureFloor($context);

        $scalePtr = BasicBlockHelper::entryAlloca($context, $double);
        $context->builder->store($double->constReal(1.0), $scalePtr);
        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iPtr);

        $scaleHead = $fn->appendBasicBlock('nf_scale_h_'.$s);
        $scaleBody = $fn->appendBasicBlock('nf_scale_b_'.$s);
        $scaleDone = $fn->appendBasicBlock('nf_scale_d_'.$s);
        $context->builder->branch($scaleHead);
        $context->builder->positionAtEnd($scaleHead);
        $i = $context->builder->load($iPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $decimals),
            $scaleBody,
            $scaleDone
        );
        $context->builder->positionAtEnd($scaleBody);
        $sc = $context->builder->load($scalePtr);
        $context->builder->store(
            $context->builder->fmul($sc, $double->constReal(10.0)),
            $scalePtr
        );
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $iPtr);
        $context->builder->branch($scaleHead);
        $context->builder->positionAtEnd($scaleDone);

        $scale = $context->builder->load($scalePtr);
        $zero = $double->constReal(0.0);
        $neg = $context->builder->fcmp(Builder::REAL_OLT, $number, $zero);
        $abs = $context->builder->select(
            $neg,
            $context->builder->fsub($zero, $number),
            $number
        );
        $scaled = $context->builder->fmul($abs, $scale);
        $biased = $context->builder->fadd($scaled, $double->constReal(0.5));
        $floored = $context->builder->call($context->lookupFunction('floor'), $biased);
        $roundedAbs = $context->builder->fdiv($floored, $scale);

        return $context->builder->select(
            $neg,
            $context->builder->fsub($zero, $roundedAbs),
            $roundedAbs
        );
    }

    /**
     * Replace or strip the '.' left by snprintf after grouping.
     * decOrd==0 → remove '.' (empty decimal_separator); else replace when != '.'.
     */
    private static function applyDecimalSeparator(
        Context $context,
        LlvmFunction $fn,
        Value $str,
        Value $decOrd,
        Value $decimals,
        string $s
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $stringMap = $context->structFieldMap['__string__'];

        $skipBb = $fn->appendBasicBlock('nf_dec_skip_'.$s);
        $workBb = $fn->appendBasicBlock('nf_dec_work_'.$s);
        $doneBb = $fn->appendBasicBlock('nf_dec_done_'.$s);

        // No fractional digits → snprintf emitted no '.'.
        $hasFrac = $context->builder->icmp(Builder::INT_UGT, $decimals, $i64->constInt(0, false));
        $dotOrd = $i64->constInt(46, false); // '.'
        $needsChange = $context->builder->icmp(Builder::INT_NE, $decOrd, $dotOrd);
        $doWork = $context->builder->and($hasFrac, $needsChange);
        $context->builder->branchIf($doWork, $workBb, $skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $len = $context->builder->load($context->builder->structGep($str, $stringMap['length']));
        $data = $context->builder->structGep($str, $stringMap['value']);
        $emptySep = $context->builder->icmp(Builder::INT_EQ, $decOrd, $i64->constInt(0, false));

        // Decimal point sits at len - decimals - 1 after thousands grouping (frac untouched).
        // Do NOT scan for '.' — thousands_separator may also be '.' (#35056).
        $dotPos = $context->builder->sub(
            $context->builder->sub($len, $decimals),
            $i64->constInt(1, false)
        );
        $noDotBb = $fn->appendBasicBlock('nf_dec_nodot_'.$s);
        $hasDotBb = $fn->appendBasicBlock('nf_dec_hasdot_'.$s);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $dotPos, $len);
        $context->builder->branchIf($inRange, $hasDotBb, $noDotBb);

        $context->builder->positionAtEnd($noDotBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hasDotBb);
        // Only rewrite when the expected slot is still snprintf's '.'.
        $slotByte = $context->builder->load($context->builder->gep($data, $dotPos));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $slotByte, $i8->constInt(46, false));
        $notDotBb = $fn->appendBasicBlock('nf_dec_slot_nodot_'.$s);
        $doEditBb = $fn->appendBasicBlock('nf_dec_edit_'.$s);
        $context->builder->branchIf($isDot, $doEditBb, $notDotBb);
        $context->builder->positionAtEnd($notDotBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doEditBb);
        $replaceBb = $fn->appendBasicBlock('nf_dec_repl_'.$s);
        $stripBb = $fn->appendBasicBlock('nf_dec_strip_'.$s);
        $context->builder->branchIf($emptySep, $stripBb, $replaceBb);

        $context->builder->positionAtEnd($replaceBb);
        $decByte = $context->builder->trunc($decOrd, $i8);
        $context->builder->store($decByte, $context->builder->gep($data, $dotPos));
        $context->builder->branch($doneBb);

        // Empty decimal_separator: drop the '.' byte (php-src concatenates without it).
        $context->builder->positionAtEnd($stripBb);
        $newLen = $context->builder->sub($len, $i64->constInt(1, false));
        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->add($newLen, $sizeT->constInt(1, false))
        );
        $outI8 = $context->builder->pointerCast($outBuf, $i8p);
        $outPosPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $srcPosPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $outPosPtr);
        $context->builder->store($i64->constInt(0, false), $srcPosPtr);
        self::copyBytes($context, $fn, $data, $outI8, $outPosPtr, $srcPosPtr, $dotPos, 'ds_'.$s);
        $context->builder->store(
            $context->builder->add($dotPos, $i64->constInt(1, false)),
            $srcPosPtr
        );
        $tail = $context->builder->sub($len, $context->builder->add($dotPos, $i64->constInt(1, false)));
        self::copyBytes($context, $fn, $data, $outI8, $outPosPtr, $srcPosPtr, $tail, 'dt_'.$s);
        $stripped = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $newLen,
            $outI8
        );
        $stripEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($str, $skipBb);
        $phi->addIncoming($str, $noDotBb);
        $phi->addIncoming($str, $notDotBb);
        $phi->addIncoming($str, $replaceBb);
        $phi->addIncoming($stripped, $stripEnd);

        return $phi;
    }

    private static function ensureFloor(Context $context): void
    {
        try {
            $context->lookupFunction('floor');

            return;
        } catch (\Throwable) {
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->getNamedFunction('floor');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'floor',
                $context->context->functionType($double, false, $double)
            );
        }
        $context->registerFunction('floor', $fn);
    }

    private static function insertThousands(
        Context $context,
        LlvmFunction $fn,
        Value $rawStr,
        Value $thouOrd,
        string $s
    ): Value {
        $stringMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $len = $context->builder->load($context->builder->structGep($rawStr, $stringMap['length']));
        $data = $context->builder->structGep($rawStr, $stringMap['value']);

        $dotPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($len, $dotPtr);
        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iPtr);

        $scanHead = $fn->appendBasicBlock('nf_scan_head_'.$s);
        $scanBody = $fn->appendBasicBlock('nf_scan_body_'.$s);
        $scanDone = $fn->appendBasicBlock('nf_scan_done_'.$s);
        $context->builder->branch($scanHead);
        $context->builder->positionAtEnd($scanHead);
        $i = $context->builder->load($iPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $len),
            $scanBody,
            $scanDone
        );
        $context->builder->positionAtEnd($scanBody);
        $byte = $context->builder->load($context->builder->gep($data, $i));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(46, false));
        $found = $fn->appendBasicBlock('nf_dot_found_'.$s);
        $next = $fn->appendBasicBlock('nf_dot_next_'.$s);
        $context->builder->branchIf($isDot, $found, $next);
        $context->builder->positionAtEnd($found);
        $context->builder->store($i, $dotPtr);
        $context->builder->branch($scanDone);
        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $iPtr);
        $context->builder->branch($scanHead);
        $context->builder->positionAtEnd($scanDone);

        $dotPos = $context->builder->load($dotPtr);
        $intLen = $dotPos;
        $smallBb = $fn->appendBasicBlock('nf_small_'.$s);
        $bigBb = $fn->appendBasicBlock('nf_big_'.$s);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULE, $intLen, $sizeT->constInt(3, false)),
            $smallBb,
            $bigBb
        );
        $context->builder->positionAtEnd($smallBb);
        $context->builder->returnValue($rawStr);

        $context->builder->positionAtEnd($bigBb);
        $thouByte = $context->builder->trunc($thouOrd, $i8);
        $mod3 = $context->builder->unsigendRem($intLen, $i64->constInt(3, false));
        $firstGroup = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $mod3, $i64->constInt(0, false)),
            $i64->constInt(3, false),
            $mod3
        );
        $rest = $context->builder->sub($intLen, $firstGroup);
        $sepCount = $context->builder->unsignedDiv($rest, $i64->constInt(3, false));
        $fracLen = $context->builder->sub($len, $dotPos);
        $outLen = $context->builder->add($len, $sepCount);

        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->add($outLen, $sizeT->constInt(1, false))
        );
        $outI8 = $context->builder->pointerCast($outBuf, $i8p);
        $outPosPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $srcPosPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $outPosPtr);
        $context->builder->store($i64->constInt(0, false), $srcPosPtr);

        self::copyBytes($context, $fn, $data, $outI8, $outPosPtr, $srcPosPtr, $firstGroup, 'a_'.$s);
        $remainPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($sepCount, $remainPtr);

        $loopHead = $fn->appendBasicBlock('nf_loop_head_'.$s);
        $loopBody = $fn->appendBasicBlock('nf_loop_body_'.$s);
        $loopDone = $fn->appendBasicBlock('nf_loop_done_'.$s);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $r = $context->builder->load($remainPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $r, $i64->constInt(0, false)),
            $loopBody,
            $loopDone
        );
        $context->builder->positionAtEnd($loopBody);
        $op = $context->builder->load($outPosPtr);
        $context->builder->store($thouByte, $context->builder->gep($outI8, $op));
        $context->builder->store($context->builder->add($op, $i64->constInt(1, false)), $outPosPtr);
        self::copyBytes($context, $fn, $data, $outI8, $outPosPtr, $srcPosPtr, $i64->constInt(3, false), 'b_'.$s);
        $context->builder->store($context->builder->sub($r, $i64->constInt(1, false)), $remainPtr);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        self::copyBytes($context, $fn, $data, $outI8, $outPosPtr, $srcPosPtr, $fracLen, 'c_'.$s);
        $finalLen = $context->builder->load($outPosPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $outI8
        );
    }

    private static function copyBytes(
        Context $context,
        LlvmFunction $fn,
        Value $src,
        Value $dst,
        Value $outPosPtr,
        Value $srcPosPtr,
        Value $count,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $iPtr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $iPtr);
        $head = $fn->appendBasicBlock('nf_cp_h_'.$tag);
        $body = $fn->appendBasicBlock('nf_cp_b_'.$tag);
        $done = $fn->appendBasicBlock('nf_cp_d_'.$tag);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iPtr);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $count),
            $body,
            $done
        );
        $context->builder->positionAtEnd($body);
        $sp = $context->builder->load($srcPosPtr);
        $op = $context->builder->load($outPosPtr);
        $b = $context->builder->load($context->builder->gep($src, $sp));
        $context->builder->store($b, $context->builder->gep($dst, $op));
        $context->builder->store($context->builder->add($sp, $i64->constInt(1, false)), $srcPosPtr);
        $context->builder->store($context->builder->add($op, $i64->constInt(1, false)), $outPosPtr);
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $iPtr);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function ensureDecls(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        LibcExtern::ensureSnprintf($context);
        foreach (
            [
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            // Reuse the module symbol when lookupFunction misses the registry (#32122 / #31894).
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }
    }
}
