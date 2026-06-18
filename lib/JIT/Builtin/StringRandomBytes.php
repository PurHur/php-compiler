<?php

declare(strict_types=1);

/**
 * LLVM implementation of __compiler_random_bytes — fill a new __string__ via /dev/urandom.
 *
 * Mirrors {@see \PHPCompiler\ext\standard\VmRandomPure} (open/read, not getrandom(3)).
 * php-src: ext/standard/random.c — php_random_bytes()
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;

final class StringRandomBytes
{
    private const URANDOM = '/dev/urandom';

    private const O_RDONLY = 0;

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
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oRdonly = $i32->constInt(self::O_RDONLY, false);
        $urandomPath = $context->builder->pointerCast(
            $context->constantFromString(self::URANDOM),
            $i8p
        );

        $badLen = $context->builder->icmp(Builder::INT_SLT, $len, $one);
        $bbBadLen = $fn->appendBasicBlock('rb_bad_len');
        $bbOpen = $fn->appendBasicBlock('rb_open');
        $context->builder->branchIf($badLen, $bbBadLen, $bbOpen);

        $context->builder->positionAtEnd($bbBadLen);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbOpen);
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $urandomPath,
            $oRdonly,
            $zeroI32
        );
        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $bbOpenFail = $fn->appendBasicBlock('rb_open_fail');
        $bbAlloc = $fn->appendBasicBlock('rb_alloc');
        $context->builder->branchIf($openFail, $bbOpenFail, $bbAlloc);

        $context->builder->positionAtEnd($bbOpenFail);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbAlloc);
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
            $context->lookupFunction('read'),
            $fd,
            $at,
            $remainSizeT
        );

        $retNonPos = $context->builder->icmp(Builder::INT_SLE, $ret, $i64->constInt(0, false));
        $bbRetBad = $fn->appendBasicBlock('rb_ret_bad');
        $bbRetPos = $fn->appendBasicBlock('rb_ret_pos');
        $context->builder->branchIf($retNonPos, $bbRetBad, $bbRetPos);

        $context->builder->positionAtEnd($bbRetBad);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbRetPos);
        $tooBig = $context->builder->icmp(Builder::INT_SGT, $ret, $remain);
        $bbRetHuge = $fn->appendBasicBlock('rb_ret_huge');
        $bbAdvance = $fn->appendBasicBlock('rb_advance');
        $context->builder->branchIf($tooBig, $bbRetHuge, $bbAdvance);

        $context->builder->positionAtEnd($bbRetHuge);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbAdvance);
        $newDone = $context->builder->add($done, $ret);
        $context->builder->store($newDone, $doneSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopEnd);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->returnValue($str);
        $context->builder->clearInsertionPosition();
    }
}
