<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\BasicBlock;
use PHPLLVM\Value;

/**
 * LLVM body for __string__htmlspecialchars — AOT standalone only (#9445). (ENT_QUOTES / ENT_COMPAT subset; mirrors VmString).
 */
final class StringHtmlspecialcharsStandaloneLlvm
{
    /** @var array<int, array{0: string, 1: int}> */
    private const ALWAYS_ESCAPE = [
        38 => ['&amp;', 5],
        60 => ['&lt;', 4],
        62 => ['&gt;', 4],
    ];

    private const QUOTE_DOUBLE = 34;
    private const QUOTE_SINGLE = 39;

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__htmlspecialchars');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $flags = $fn->getParam(1);
        [$quoteBoth, $quoteDouble, $entHtml5] = self::quoteFlags($context, $flags);

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

        self::countLoop(
            $context,
            $fn,
            $srcChars,
            $len,
            $iSlot,
            $outLenSlot,
            $quoteBoth,
            $quoteDouble,
            $entHtml5,
            $i64,
            $zero,
            $one
        );

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLoop(
            $context,
            $fn,
            $srcChars,
            $len,
            $destChars,
            $iSlot,
            $posSlot,
            $quoteBoth,
            $quoteDouble,
            $entHtml5,
            $i64,
            $zero,
            $one,
            $charPtr
        );

        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value} i1 quoteBoth, i1 quoteDouble, i1 entHtml5 (VmString parity)
     */
    private static function quoteFlags(Context $context, Value $flags): array
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $entQuotes = $i64->constInt(3, false);
        $entCompat = $i64->constInt(2, false);
        $entHtml5Mask = $i64->constInt(48, false);

        $flagsAndQuotes = $context->builder->and($flags, $entQuotes);
        $quoteBoth = $context->builder->icmp(Builder::INT_NE, $flagsAndQuotes, $zero);

        $flagsAndCompat = $context->builder->and($flags, $entCompat);
        $quoteDouble = $context->builder->and(
            $context->builder->not($quoteBoth),
            $context->builder->icmp(Builder::INT_NE, $flagsAndCompat, $zero)
        );

        $flagsAndHtml5 = $context->builder->and($flags, $entHtml5Mask);
        $entHtml5 = $context->builder->icmp(Builder::INT_NE, $flagsAndHtml5, $zero);

        return [$quoteBoth, $quoteDouble, $entHtml5];
    }

    private static function countLoop(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        Value $quoteBoth,
        Value $quoteDouble,
        Value $entHtml5,
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
        $add = self::replacementLen($context, $chI64, $quoteBoth, $quoteDouble, $one);
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
        Value $quoteBoth,
        Value $quoteDouble,
        Value $entHtml5,
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
        self::writeChar($context, $fn, $destAt, $ch, $chI64, $quoteBoth, $quoteDouble, $entHtml5, $afterChar, $charPtr, $i64);
        $context->builder->positionAtEnd($afterChar);
        $step = self::replacementLen($context, $chI64, $quoteBoth, $quoteDouble, $one);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $step), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function replacementLen(
        Context $context,
        Value $chI64,
        Value $quoteBoth,
        Value $quoteDouble,
        Value $defaultLen
    ): Value {
        $i64 = $defaultLen->typeOf();
        $len = $defaultLen;
        foreach (self::ALWAYS_ESCAPE as $ord => [, $replLen]) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $chI64,
                $i64->constInt($ord, false)
            );
            $len = $context->builder->select(
                $match,
                $i64->constInt($replLen, false),
                $len
            );
        }

        $is34 = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(self::QUOTE_DOUBLE, false));
        $escape34 = $context->builder->or($quoteBoth, $quoteDouble);
        $len = $context->builder->select(
            $context->builder->and($is34, $escape34),
            $i64->constInt(6, false),
            $len
        );

        $is39 = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(self::QUOTE_SINGLE, false));
        $len = $context->builder->select(
            $context->builder->and($is39, $quoteBoth),
            $i64->constInt(6, false),
            $len
        );

        return $len;
    }

    private static function writeChar(
        Context $context,
        Value $fn,
        Value $destAt,
        Value $ch,
        Value $chI64,
        Value $quoteBoth,
        Value $quoteDouble,
        Value $entHtml5,
        BasicBlock $mergeBlock,
        $charPtr,
        $i64
    ): void {
        $resume = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('htmlspecialchars_char_entry');
        $defaultBlock = $fn->appendBasicBlock('htmlspecialchars_char_default');
        $escapeDouble = $context->builder->or($quoteBoth, $quoteDouble);
        $context->builder->positionAtEnd($resume);
        $context->builder->branch($entry);

        $fallthrough = $entry;
        foreach (array_keys(self::ALWAYS_ESCAPE) as $ord) {
            $matchBlock = $fn->appendBasicBlock('htmlspecialchars_char_match_'.$ord);
            $nextBlock = $fn->appendBasicBlock('htmlspecialchars_char_next_'.$ord);

            $context->builder->positionAtEnd($fallthrough);
            $context->builder->branchIf(
                $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false)),
                $matchBlock,
                $nextBlock
            );

            $context->builder->positionAtEnd($matchBlock);
            [$text, $textLen] = self::ALWAYS_ESCAPE[$ord];
            $src = $context->builder->pointerCast($context->constantFromString($text), $charPtr);
            $context->intrinsic->memcpy($destAt, $src, $i64->constInt($textLen, false), false);
            $context->builder->branch($mergeBlock);

            $fallthrough = $nextBlock;
        }

        $fallthrough = self::writeConditionalQuote(
            $context,
            $fn,
            $fallthrough,
            $destAt,
            $ch,
            $chI64,
            self::QUOTE_DOUBLE,
            '&quot;',
            6,
            $escapeDouble,
            $mergeBlock,
            $charPtr,
            $i64,
            'double'
        );

        $fallthrough = self::writeConditionalQuoteHtml5(
            $context,
            $fn,
            $fallthrough,
            $destAt,
            $ch,
            $chI64,
            self::QUOTE_SINGLE,
            '&apos;',
            '&#039;',
            6,
            $quoteBoth,
            $entHtml5,
            $mergeBlock,
            $charPtr,
            $i64,
            'single'
        );

        $context->builder->positionAtEnd($fallthrough);
        $context->builder->branch($defaultBlock);

        $context->builder->positionAtEnd($defaultBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($mergeBlock);
    }

    private static function writeConditionalQuote(
        Context $context,
        Value $fn,
        BasicBlock $fallthrough,
        Value $destAt,
        Value $ch,
        Value $chI64,
        int $ord,
        string $entity,
        int $entityLen,
        Value $escapeWhen,
        BasicBlock $mergeBlock,
        $charPtr,
        $i64,
        string $suffix
    ): BasicBlock {
        $matchBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_match');
        $entityBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_entity');
        $literalBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_literal');
        $nextBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_next');

        $context->builder->positionAtEnd($fallthrough);
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false));
        $context->builder->branchIf($isQuote, $matchBlock, $nextBlock);

        $context->builder->positionAtEnd($matchBlock);
        $context->builder->branchIf($escapeWhen, $entityBlock, $literalBlock);

        $context->builder->positionAtEnd($entityBlock);
        $src = $context->builder->pointerCast($context->constantFromString($entity), $charPtr);
        $context->intrinsic->memcpy($destAt, $src, $i64->constInt($entityLen, false), false);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($literalBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($mergeBlock);

        return $nextBlock;
    }

    private static function writeConditionalQuoteHtml5(
        Context $context,
        Value $fn,
        BasicBlock $fallthrough,
        Value $destAt,
        Value $ch,
        Value $chI64,
        int $ord,
        string $html5Entity,
        string $legacyEntity,
        int $entityLen,
        Value $escapeWhen,
        Value $useHtml5Entity,
        BasicBlock $mergeBlock,
        $charPtr,
        $i64,
        string $suffix
    ): BasicBlock {
        $matchBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_match');
        $entityBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_entity');
        $html5Block = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_html5');
        $legacyBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_legacy');
        $literalBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_literal');
        $nextBlock = $fn->appendBasicBlock('htmlspecialchars_char_quote_'.$suffix.'_next');

        $context->builder->positionAtEnd($fallthrough);
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt($ord, false));
        $context->builder->branchIf($isQuote, $matchBlock, $nextBlock);

        $context->builder->positionAtEnd($matchBlock);
        $context->builder->branchIf($escapeWhen, $entityBlock, $literalBlock);

        $context->builder->positionAtEnd($entityBlock);
        $context->builder->branchIf($useHtml5Entity, $html5Block, $legacyBlock);

        $context->builder->positionAtEnd($html5Block);
        $src = $context->builder->pointerCast($context->constantFromString($html5Entity), $charPtr);
        $context->intrinsic->memcpy($destAt, $src, $i64->constInt($entityLen, false), false);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($legacyBlock);
        $src = $context->builder->pointerCast($context->constantFromString($legacyEntity), $charPtr);
        $context->intrinsic->memcpy($destAt, $src, $i64->constInt($entityLen, false), false);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($literalBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->branch($mergeBlock);

        return $nextBlock;
    }
}
