<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for htmlspecialchars() with default ENT_QUOTES (UTF-8).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

final class JitHtmlspecialchars
{
    /**
     * Replacement text and byte length for ENT_QUOTES (default web usage).
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const SPECIAL = [
        38 => ['&amp;', 5],   // &
        60 => ['&lt;', 4],    // <
        62 => ['&gt;', 4],    // >
        34 => ['&quot;', 6],  // "
        39 => ['&#039;', 6],  // '
    ];

    public static function escape(Context $context, Value $strPtr): Value
    {
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($copy, $map['length'])
        );
        $charPtr = $context->builder->structGep($copy, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $outLenSlot = $context->builder->alloca($i64, 1, 'htmlspecialchars_out_len');
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1, 'htmlspecialchars_i');
        $context->builder->store($zero, $iSlot);

        self::countLoop($context, $charPtr, $len, $iSlot, $outLenSlot, $i64, $zero, $one);

        $outLenFinal = $context->builder->load($outLenSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $outLenFinal, $zero);

        $allocDone = BasicBlockHelper::append($context, 'htmlspecialchars_alloc_done');
        $allocEmpty = BasicBlockHelper::append($context, 'htmlspecialchars_alloc_empty');
        $allocBody = BasicBlockHelper::append($context, 'htmlspecialchars_alloc_body');
        $context->builder->branchIf($isEmpty, $allocEmpty, $allocBody);

        $context->builder->positionAtEnd($allocEmpty);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($allocDone);

        $context->builder->positionAtEnd($allocBody);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLenFinal);
        $context->builder->store(
            $outLenFinal,
            $context->builder->structGep($dest, $map['length'])
        );
        $destPtr = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1, 'htmlspecialchars_pos');
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop($context, $charPtr, $len, $destPtr, $iSlot, $posSlot, $i64, $zero, $one);
        $afterWrite = $context->builder->getInsertBlock();
        $context->builder->branch($allocDone);

        $context->builder->positionAtEnd($allocDone);
        $result = $context->builder->phi($emptyStr->typeOf());
        $result->addIncoming($emptyStr, $allocEmpty);
        $result->addIncoming($dest, $afterWrite);

        return $result;
    }

    private static function countLoop(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        Type $i64,
        Value $zero,
        Value $one
    ): void {
        $done = BasicBlockHelper::append($context, 'htmlspecialchars_count_done');
        $head = BasicBlockHelper::append($context, 'htmlspecialchars_count_head');
        $body = BasicBlockHelper::append($context, 'htmlspecialchars_count_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $i32 = $context->getTypeFromString('int32');
        $ch = $context->builder->load($context->builder->gep($charPtr, $i));
        $chI32 = $context->builder->zExt($ch, $i32);
        $addLen = self::replacementLength($context, $chI32, $one);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outLen, $addLen),
            $outLenSlot
        );
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function writeLoop(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $destPtr,
        Value $iSlot,
        Value $posSlot,
        Type $i64,
        Value $zero,
        Value $one
    ): void {
        $done = BasicBlockHelper::append($context, 'htmlspecialchars_write_done');
        $head = BasicBlockHelper::append($context, 'htmlspecialchars_write_head');
        $body = BasicBlockHelper::append($context, 'htmlspecialchars_write_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $i32 = $context->getTypeFromString('int32');
        $ch = $context->builder->load($context->builder->gep($charPtr, $i));
        $chI32 = $context->builder->zExt($ch, $i32);
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destPtr, $pos);
        self::writeChar($context, $destAt, $ch, $chI32);
        $step = self::replacementLength($context, $chI32, $one);
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $step),
            $posSlot
        );
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function replacementLength(Context $context, Value $chI32, Value $defaultLen): Value
    {
        $len = $defaultLen;
        foreach (self::SPECIAL as $ord => [, $replLen]) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $chI32,
                $chI32->typeOf()->constInt($ord, false)
            );
            $len = $context->builder->select(
                $match,
                $len->typeOf()->constInt($replLen, false),
                $len
            );
        }

        return $len;
    }

    private static function writeChar(Context $context, Value $destAt, Value $ch, Value $chI32): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $chI32->typeOf();

        $done = BasicBlockHelper::append($context, 'htmlspecialchars_write_char_done');
        $defaultBlock = BasicBlockHelper::append($context, 'htmlspecialchars_write_char_default');

        $ords = array_keys(self::SPECIAL);
        $matchBlocks = [];
        $nextBlocks = [];
        foreach ($ords as $idx => $ord) {
            $matchBlocks[$ord] = BasicBlockHelper::append($context, 'htmlspecialchars_write_char_match_'.$ord);
            if ($idx + 1 < count($ords)) {
                $nextBlocks[$ord] = BasicBlockHelper::append($context, 'htmlspecialchars_write_char_next_'.$ord);
            }
        }

        $checkBlock = $context->builder->getInsertBlock();
        foreach ($ords as $idx => $ord) {
            $context->builder->positionAtEnd($checkBlock);
            $fallthrough = $idx + 1 < count($ords) ? $nextBlocks[$ord] : $defaultBlock;
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt($ord, false)),
                $matchBlocks[$ord],
                $fallthrough
            );

            $context->builder->positionAtEnd($matchBlocks[$ord]);
            [$text, $textLen] = self::SPECIAL[$ord];
            $src = $context->builder->pointerCast($context->constantFromString($text), $charPtr);
            $context->intrinsic->memcpy($destAt, $src, $i32->constInt($textLen, false), false);
            $context->builder->branch($done);

            if ($idx + 1 < count($ords)) {
                $checkBlock = $nextBlocks[$ord];
            }
        }

        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
