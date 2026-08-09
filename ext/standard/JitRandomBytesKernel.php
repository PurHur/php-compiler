<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for random_bytes() — thin libc open/read /dev/urandom (#21186, #29531).
 *
 * Used while NestedJIT compiles {@see RandomBytesJitHelper} `@random_bytes` so the helper
 * does not re-enter `__compiler_random_bytes` (gethostname #29364 / putenv #29334 shape).
 * User-script AOT always goes through {@see RandomBytesJitHelper} via
 * {@see \PHPCompiler\JIT\Builtin\StringRandomBytes}. Kernel Internal deleted (#29531).
 * Mirrors {@see VmRandomPure} (open/read, not getrandom(3)).
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class JitRandomBytesKernel
{
    private const URANDOM = '/dev/urandom';

    private const O_RDONLY = 0;

    /** @return Value `__string__*` — filled from /dev/urandom; exits on failure */
    public static function invoke(Context $context, Value $len): Value
    {
        LibcExtern::register($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
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
        $bbBadLen = $fn->appendBasicBlock('rb_kernel_bad_len');
        $bbOpen = $fn->appendBasicBlock('rb_kernel_open');
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
        $bbOpenFail = $fn->appendBasicBlock('rb_kernel_open_fail');
        $bbAlloc = $fn->appendBasicBlock('rb_kernel_alloc');
        $context->builder->branchIf($openFail, $bbOpenFail, $bbAlloc);

        $context->builder->positionAtEnd($bbOpenFail);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbAlloc);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $strMap = $context->structFieldMap['__string__'];
        $dataField = $context->builder->structGep($str, $strMap['value']);
        $buf = $context->builder->pointerCast($dataField, $i8p);

        $doneSlot = $context->builder->alloca($i64, 1, 'rb_kernel_done');
        $context->builder->store($i64->constInt(0, false), $doneSlot);

        $loopHead = $fn->appendBasicBlock('rb_kernel_loop_head');
        $loopBody = $fn->appendBasicBlock('rb_kernel_loop_body');
        $loopEnd = $fn->appendBasicBlock('rb_kernel_loop_end');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $done = $context->builder->load($doneSlot);
        $needMore = $context->builder->icmp(Builder::INT_SLT, $done, $len);
        $context->builder->branchIf($needMore, $loopBody, $loopEnd);

        $context->builder->positionAtEnd($loopBody);
        $remain = $context->builder->sub($len, $done);
        $at = $context->builder->inBoundsGep($buf, $done);
        $ret = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $at,
            $remain
        );

        $retNonPos = $context->builder->icmp(Builder::INT_SLE, $ret, $i64->constInt(0, false));
        $bbRetBad = $fn->appendBasicBlock('rb_kernel_ret_bad');
        $bbRetPos = $fn->appendBasicBlock('rb_kernel_ret_pos');
        $context->builder->branchIf($retNonPos, $bbRetBad, $bbRetPos);

        $context->builder->positionAtEnd($bbRetBad);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('exit'), $oneI32);
        self::buildUnreachable($context);

        $context->builder->positionAtEnd($bbRetPos);
        $tooBig = $context->builder->icmp(Builder::INT_SGT, $ret, $remain);
        $bbRetHuge = $fn->appendBasicBlock('rb_kernel_ret_huge');
        $bbAdvance = $fn->appendBasicBlock('rb_kernel_advance');
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

        return $str;
    }

    private static function buildUnreachable(Context $context): void
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for JitRandomBytesKernel');
        }
        $b->llvm->lib->LLVMBuildUnreachable($b->builder);
    }
}
