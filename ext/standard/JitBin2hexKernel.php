<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for thin-standalone AOT bin2hex — hex encode loop (#19344, #20011).
 *
 * Used when {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()} so nested
 * {@see Bin2hexJitHelper} is not ExternalMethod-stubbed under minimal init (#16075).
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class JitBin2hexKernel
{
    /** Emit hex encode loop; builder must be positioned at the bridge entry block. */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $input = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $lenI64 = $context->builder->zExt($len, $i64);
        $hexLen = $context->builder->mul($lenI64, $i64->constInt(2, false));
        $hexStr = $context->builder->call($context->lookupFunction('__string__alloc'), $hexLen);
        $context->builder->store($hexLen, $context->builder->structGep($hexStr, $map['length']));
        $srcPtr = $context->builder->structGep($input, $map['value']);
        $destPtr = $context->builder->structGep($hexStr, $map['value']);
        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789abcdef'),
            $charPtr
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'b2h_idx');
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loopHead = $fn->appendBasicBlock('b2h_kernel_head');
        $loopBody = $fn->appendBasicBlock('b2h_kernel_body');
        $loopDone = $fn->appendBasicBlock('b2h_kernel_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $lenI64);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $byte = $context->builder->load($context->builder->gep($srcPtr, $idx));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));
        $outPos = $context->builder->mulNoSignedWrap($idxI32, $i32->constInt(2, false));
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $context->builder->add($outPos, $i32->constInt(1, false)))
        );
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($hexStr);
    }
}
