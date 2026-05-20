<?php

declare(strict_types=1);

/**
 * LLVM implementation of __compiler_random_bytes — fill a new __string__ via getrandom(3).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;

final class StringRandomBytes
{
    private static function buildUnreachable(Context $context): void
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for __compiler_random_bytes');
        }
        $b->llvm->lib->LLVMBuildUnreachable($b->builder);
    }

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_random_bytes');
        $entry = $fn->appendBasicBlock('rb_entry');
        $context->builder->positionAtEnd($entry);

        $len = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i64->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $badLen = $context->builder->icmp(Builder::INT_SLT, $len, $one);
        $bbBadLen = $fn->appendBasicBlock('rb_bad_len');
        $bbOk = $fn->appendBasicBlock('rb_ok');
        $context->builder->branchIf($badLen, $bbBadLen, $bbOk);

        $context->builder->positionAtEnd($bbBadLen);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbOk);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $strMap = $context->structFieldMap['__string__'];
        $dataField = $context->builder->structGep($str, $strMap['value']);
        $buf = $context->builder->pointerCast($dataField, $i8p);

        $doneSlot = $context->builder->alloca($i64, 1, 'rb_done');
        $context->builder->store($i64->constInt(0, false), $doneSlot);

        $loopHead = $fn->appendBasicBlock('rb_loop_head');
        $loopBody = $fn->appendBasicBlock('rb_loop_body');
        $loopEnd = $fn->appendBasicBlock('rb_loop_end');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $done = $context->builder->load($doneSlot);
        $needMore = $context->builder->icmp(Builder::INT_SLT, $done, $len);
        $context->builder->branchIf($needMore, $loopBody, $loopEnd);

        $context->builder->positionAtEnd($loopBody);
        $remain = $context->builder->sub($len, $done);
        $at = $context->builder->inBoundsGep($buf, $done);
        $remainSizeT = $context->builder->truncOrBitCast($remain, $sizeT);

        $ret = $context->builder->call(
            $context->lookupFunction('getrandom'),
            $at,
            $remainSizeT,
            $zeroI32
        );

        $retNeg = $context->builder->icmp(Builder::INT_SLT, $ret, $i64->constInt(0, false));
        $bbRetBad = $fn->appendBasicBlock('rb_ret_bad');
        $bbRetNonNeg = $fn->appendBasicBlock('rb_ret_nonneg');
        $context->builder->branchIf($retNeg, $bbRetBad, $bbRetNonNeg);

        $context->builder->positionAtEnd($bbRetBad);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbRetNonNeg);
        $retZero = $context->builder->icmp(Builder::INT_EQ, $ret, $i64->constInt(0, false));
        $bbRetZero = $fn->appendBasicBlock('rb_ret_zero');
        $bbRetPos = $fn->appendBasicBlock('rb_ret_pos');
        $context->builder->branchIf($retZero, $bbRetZero, $bbRetPos);

        $context->builder->positionAtEnd($bbRetZero);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbRetPos);
        $tooBig = $context->builder->icmp(Builder::INT_SGT, $ret, $remain);
        $bbRetHuge = $fn->appendBasicBlock('rb_ret_huge');
        $bbAdvance = $fn->appendBasicBlock('rb_advance');
        $context->builder->branchIf($tooBig, $bbRetHuge, $bbAdvance);

        $context->builder->positionAtEnd($bbRetHuge);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbAdvance);
        $newDone = $context->builder->add($done, $ret);
        $context->builder->store($newDone, $doneSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopEnd);
        $context->builder->returnValue($str);
        $context->builder->clearInsertionPosition();
    }
}
