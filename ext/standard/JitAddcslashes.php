<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** LLVM JIT helper for addcslashes() — VmString parity (#3356, #5652). */
final class JitAddcslashes
{
    public static function escape(Context $context, JITVariable $subjectArg, JITVariable $charlistArg): Value
    {
        $subjectLit = JitStringArg::compileTimeLiteral($subjectArg) ?? $subjectArg->compileTimeString;
        $charlistLit = JitStringArg::compileTimeLiteral($charlistArg) ?? $charlistArg->compileTimeString;
        if (null !== $subjectLit && null !== $charlistLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::addcslashes($subjectLit, $charlistLit))
            );
        }

        $subject = JitStringArg::lower($context, $subjectArg, 'addcslashes() argument #1');
        if (null !== $charlistLit) {
            StringCslashes::ensureLinked($context);

            return $context->builder->call(
                $context->lookupFunction('__compiler_addcslashes'),
                $subject,
                $context->builder->load($context->constantStringFromString($charlistLit))
            );
        }

        StringCslashes::ensureLinked($context);
        $charlist = JitStringArg::lower($context, $charlistArg, 'addcslashes() argument #2');

        return $context->builder->call(
            $context->lookupFunction('__compiler_addcslashes'),
            $subject,
            $charlist
        );
    }

    /** @param array<int, bool> $mask */
    public static function escapeWithMaskArray(Context $context, Value $subject, array $mask): Value
    {
        $maskSlot = self::storeMaskArray($context, $mask);

        return self::escapeWithMaskSlot($context, $subject, $maskSlot);
    }

    public static function escapeWithMaskSlot(Context $context, Value $subject, Value $maskSlot, ?LlvmFunction $fn = null): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $backslash = $i8->constInt(92, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $subject);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        if (null === $fn) {
            $insert = $context->builder->getInsertBlock();
            if (null === $insert) {
                throw new \LogicException('addcslashes mask escape requires an active LLVM insert block or function');
            }
            $fn = $insert->getParent();
        }
        self::countLoop($context, $fn, $srcChars, $len, $maskSlot, $iSlot, $outLenSlot, $i64, $zero, $one, $two);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);
        self::writeLoop($context, $fn, $srcChars, $len, $destChars, $maskSlot, $iSlot, $posSlot, $i64, $zero, $one, $two, $backslash);

        return $dest;
    }

    /** @param array<int, bool> $mask */
    public static function storeMaskArray(Context $context, array $mask): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $maskSlot = $context->builder->alloca($i8, 256, 'addcslashes_mask');
        $context->intrinsic->memset($maskSlot, $i8->constInt(0, false), $i64->constInt(256, false), false);
        foreach ($mask as $ord => $flag) {
            if ($flag) {
                $context->builder->store(
                    $i8->constInt(1, false),
                    $context->builder->gep($maskSlot, $i64->constInt($ord, false))
                );
            }
        }

        return $maskSlot;
    }

    private static function countLoop(
        Context $context,
        LlvmFunction $fn,
        Value $srcChars,
        Value $len,
        Value $maskSlot,
        Value $iSlot,
        Value $outLenSlot,
        $i64,
        Value $zero,
        Value $one,
        Value $two
    ): void {
        $head = $fn->appendBasicBlock('jit_addcslashes_count_head');
        $body = $fn->appendBasicBlock('jit_addcslashes_count_body');
        $done = $fn->appendBasicBlock('jit_addcslashes_count_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $escape = self::maskHit($context, $maskSlot, $ch);
        $add = $context->builder->select($escape, $two, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $add), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function writeLoop(
        Context $context,
        LlvmFunction $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $maskSlot,
        Value $iSlot,
        Value $posSlot,
        $i64,
        Value $zero,
        Value $one,
        Value $two,
        Value $backslash
    ): void {
        $head = $fn->appendBasicBlock('jit_addcslashes_write_head');
        $body = $fn->appendBasicBlock('jit_addcslashes_write_body');
        $done = $fn->appendBasicBlock('jit_addcslashes_write_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $escape = self::maskHit($context, $maskSlot, $ch);

        $escapedBlock = $fn->appendBasicBlock('jit_addcslashes_write_escaped');
        $plainBlock = $fn->appendBasicBlock('jit_addcslashes_write_plain');
        $afterBlock = $fn->appendBasicBlock('jit_addcslashes_write_after');
        $context->builder->branchIf($escape, $escapedBlock, $plainBlock);

        $context->builder->positionAtEnd($escapedBlock);
        $context->builder->store($backslash, $destAt);
        $context->builder->store($ch, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $two), $posSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($afterBlock);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function maskHit(Context $context, Value $maskSlot, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $chI64 = $context->builder->zExt($ch, $i64);
        $hit = $context->builder->load($context->builder->gep($maskSlot, $chI64));

        return $context->builder->icmp(Builder::INT_NE, $hit, $context->getTypeFromString('int8')->constInt(0, false));
    }
}
