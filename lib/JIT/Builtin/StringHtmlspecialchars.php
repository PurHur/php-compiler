<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\BasicBlock;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__htmlspecialchars (ENT_QUOTES subset).
 */
final class StringHtmlspecialchars
{
    /** @var array<int, array{0: string, 1: int}> */
    private const SPECIAL = [
        38 => ['&amp;', 5],
        60 => ['&lt;', 4],
        62 => ['&gt;', 4],
        34 => ['&quot;', 6],
        39 => ['&#039;', 6],
    ];

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__htmlspecialchars');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $i64, $zero, $one);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop($context, $fn, $srcChars, $len, $destChars, $iSlot, $posSlot, $i64, $zero, $one, $charPtr);

        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }

    private static function countLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        $i64,
        Value $zero,
        Value $one
    ): void {
        $head = $fn->appendBasicBlock('htmlspecialchars_count_head');
        $body = $fn->appendBasicBlock('htmlspecialchars_count_body');
        $done = $fn->appendBasicBlock('htmlspecialchars_count_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $add = self::replacementLen($context, $chI64, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $add), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function writeLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $iSlot,
        Value $posSlot,
        $i64,
        Value $zero,
        Value $one,
        $charPtr
    ): void {
        $head = $fn->appendBasicBlock('htmlspecialchars_write_head');
        $body = $fn->appendBasicBlock('htmlspecialchars_write_body');
        $done = $fn->appendBasicBlock('htmlspecialchars_write_done');

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $afterChar = $fn->appendBasicBlock('htmlspecialchars_write_after_char');
        self::writeChar($context, $fn, $destAt, $ch, $chI64, $afterChar, $charPtr, $i64);
        $context->builder->positionAtEnd($afterChar);
        $step = self::replacementLen($context, $chI64, $one);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $step), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function replacementLen(Context $context, Value $chI64, Value $defaultLen): Value
    {
        $len = $defaultLen;
        foreach (self::SPECIAL as $ord => [, $replLen]) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $chI64,
                $chI64->typeOf()->constInt($ord, false)
            );
            $len = $context->builder->select(
                $match,
                $len->typeOf()->constInt($replLen, false),
                $len
            );
        }

        return $len;
    }

    private static function writeChar(
        Context $context,
        Value $fn,
        Value $destAt,
        Value $ch,
        Value $chI64,
        BasicBlock $mergeBlock,
        $charPtr,
        $i64
    ): void {
        $resume = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('htmlspecialchars_char_entry');
        $defaultBlock = $fn->appendBasicBlock('htmlspecialchars_char_default');
        $context->builder->positionAtEnd($resume);
        $context->builder->branch($entry);

        $fallthrough = $entry;
        foreach (array_keys(self::SPECIAL) as $ord) {
            $matchBlock = $fn->appendBasicBlock('htmlspecialchars_char_match_'.$ord);
            $nextBlock = $fn->appendBasicBlock('htmlspecialchars_char_next_'.$ord);

            $context->builder->positionAtEnd($fallthrough);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false)),
                $matchBlock,
                $nextBlock
            );

            $context->builder->positionAtEnd($matchBlock);
            [$text, $textLen] = self::SPECIAL[$ord];
            $src = $context->builder->pointerCast($context->constantFromString($text), $charPtr);
            $context->intrinsic->memcpy($destAt, $src, $i64->constInt($textLen, false), false);
            $context->builder->branch($mergeBlock);

            $fallthrough = $nextBlock;
        }

        $context->builder->positionAtEnd($fallthrough);
        $context->builder->branch($defaultBlock);

        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($mergeBlock);
    }
}
