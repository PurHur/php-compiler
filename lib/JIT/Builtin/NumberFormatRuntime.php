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
 */
final class NumberFormatRuntime
{
    private const BUF_SIZE = 128;

    private static int $seq = 0;

    /** Emit bridge body; ends with returnValue in $fn. */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn, Value $thouOrd): void
    {
        self::ensureDecls($context);
        ++self::$seq;
        $s = (string) self::$seq;

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $stringMap = $context->structFieldMap['__string__'];

        $number = $fn->getParam(0);
        $decimals = $fn->getParam(1);

        $decI32 = $context->builder->trunc($decimals, $i32);
        $negDec = $context->builder->icmp(Builder::INT_SLT, $decI32, $i32->constInt(0, true));
        $decI32 = $context->builder->select(
            $negDec,
            $i32->constInt(0, true),
            $decI32
        );

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
        $doneBb = $fn->appendBasicBlock('nf_done_'.$s);
        $hasSep = $context->builder->icmp(Builder::INT_UGT, $thouOrd, $i64->constInt(0, false));
        $context->builder->branchIf($hasSep, $groupBb, $noSepBb);

        $context->builder->positionAtEnd($noSepBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($groupBb);
        $grouped = self::insertThousands($context, $fn, $rawStr, $thouOrd, $s);
        $groupEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($rawStr, $noSepBb);
        $phi->addIncoming($grouped, $groupEnd);
        $context->builder->returnValue($phi);
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
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                'snprintf' => [$i32, true, [$charPtr, $sizeT, $charPtr]],
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $ft = $context->context->functionType($ret, $vararg, ...$params);
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
    }
}
