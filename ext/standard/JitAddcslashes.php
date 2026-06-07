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

/** LLVM JIT helper for addcslashes() — VmString parity (#3356, #5652, #4736). */
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
        $add = $context->builder->select($escape, self::escapedOutputLen($context, $ch), $one);
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
        $newPos = self::writeEscapedByte($context, $fn, $destChars, $pos, $ch, $backslash);
        $context->builder->store($newPos, $posSlot);
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

    /** Output length for one escaped byte (php-src php_addcslashes_str). */
    private static function escapedOutputLen(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $two = $i64->constInt(2, false);
        $four = $i64->constInt(4, false);
        $ord = $context->builder->zExt($ch, $i64);
        $nonPrintable = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $ord, $i64->constInt(32, false)),
            $context->builder->icmp(Builder::INT_SGT, $ord, $i64->constInt(126, false))
        );

        return $context->builder->select(
            $nonPrintable,
            self::nonPrintableEscapedLen($context, $ord, $two, $four),
            $two
        );
    }

    private static function nonPrintableEscapedLen(Context $context, Value $ord, Value $two, Value $four): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $named = [10, 9, 13, 7, 11, 8, 12];
        $chain = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt($named[0], false));
        for ($idx = 1; $idx < \count($named); ++$idx) {
            $chain = $context->builder->or(
                $chain,
                $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt($named[$idx], false))
            );
        }

        return $context->builder->select($chain, $two, $four);
    }

    private static function writeEscapedByte(
        Context $context,
        LlvmFunction $fn,
        Value $destChars,
        Value $pos,
        Value $ch,
        Value $backslash
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $ord = $context->builder->zExt($ch, $i64);
        $nonPrintable = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $ord, $i64->constInt(32, false)),
            $context->builder->icmp(Builder::INT_SGT, $ord, $i64->constInt(126, false))
        );

        $printableBlock = $fn->appendBasicBlock('jit_addcslashes_write_printable');
        $nonPrintableBlock = $fn->appendBasicBlock('jit_addcslashes_write_nonprintable');
        $doneBlock = $fn->appendBasicBlock('jit_addcslashes_write_escape_done');
        $context->builder->branchIf($nonPrintable, $nonPrintableBlock, $printableBlock);

        $context->builder->positionAtEnd($printableBlock);
        $context->builder->store($backslash, $context->builder->gep($destChars, $pos));
        $context->builder->store($ch, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $printablePos = $context->builder->addNoSignedWrap($pos, $two);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nonPrintableBlock);
        $nonPrintablePos = self::writeNonPrintableEscapedByte($context, $fn, $destChars, $pos, $backslash, $ord);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $posPhi = $context->builder->phi($i64);
        $posPhi->addIncoming($printablePos, $printableBlock);
        $posPhi->addIncoming($nonPrintablePos, $nonPrintableBlock);

        return $posPhi;
    }

    private static function writeNonPrintableEscapedByte(
        Context $context,
        LlvmFunction $fn,
        Value $destChars,
        Value $pos,
        Value $backslash,
        Value $ord
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $named = [
            10 => 110,
            9 => 116,
            13 => 114,
            7 => 97,
            11 => 118,
            8 => 98,
            12 => 102,
        ];

        $doneBlock = $fn->appendBasicBlock('jit_addcslashes_write_named_done');
        $nextChain = $context->builder->getInsertBlock();
        $incomingBlocks = [];
        $incomingValues = [];
        foreach ($named as $byteOrd => $letterOrd) {
            $context->builder->positionAtEnd($nextChain);
            $caseBlock = $fn->appendBasicBlock('jit_addcslashes_write_named_'.$byteOrd);
            $fallBlock = $fn->appendBasicBlock('jit_addcslashes_write_named_fall_'.$byteOrd);
            $match = $context->builder->icmp(Builder::INT_EQ, $ord, $i64->constInt($byteOrd, false));
            $context->builder->branchIf($match, $caseBlock, $fallBlock);
            $context->builder->positionAtEnd($caseBlock);
            $context->builder->store($backslash, $context->builder->gep($destChars, $pos));
            $context->builder->store(
                $i8->constInt($letterOrd, false),
                $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one))
            );
            $incomingBlocks[] = $caseBlock;
            $incomingValues[] = $context->builder->addNoSignedWrap($pos, $two);
            $context->builder->branch($doneBlock);
            $nextChain = $fallBlock;
        }

        $context->builder->positionAtEnd($nextChain);
        $incomingBlocks[] = $nextChain;
        $incomingValues[] = self::writeOctalEscapedByte($context, $destChars, $pos, $ord, $backslash);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $posPhi = $context->builder->phi($i64);
        foreach ($incomingBlocks as $idx => $block) {
            $posPhi->addIncoming($incomingValues[$idx], $block);
        }

        return $posPhi;
    }

    private static function writeOctalEscapedByte(
        Context $context,
        Value $destChars,
        Value $pos,
        Value $ord,
        Value $backslash
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $zeroDigit = $i64->constInt(48, false);
        $seven = $i64->constInt(7, false);

        $context->builder->store($backslash, $context->builder->gep($destChars, $pos));
        $d1 = $context->builder->trunc(
            $context->builder->add($zeroDigit, $context->builder->and($context->builder->lShr($ord, $i64->constInt(6, false)), $seven)),
            $i8
        );
        $d2 = $context->builder->trunc(
            $context->builder->add($zeroDigit, $context->builder->and($context->builder->lShr($ord, $i64->constInt(3, false)), $seven)),
            $i8
        );
        $d3 = $context->builder->trunc(
            $context->builder->add($zeroDigit, $context->builder->and($ord, $seven)),
            $i8
        );
        $context->builder->store($d1, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($d2, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $two)));
        $context->builder->store($d3, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos, $three)));

        return $context->builder->addNoSignedWrap($pos, $four);
    }
}
