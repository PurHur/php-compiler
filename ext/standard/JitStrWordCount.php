<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for str_word_count() format 0 (ASCII letter words; issue #2382).
 */
final class JitStrWordCount
{
    private static int $blockSerial = 0;

    public static function count(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $data = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $id = (string) (++self::$blockSerial);
        $posSlot = $context->builder->alloca($i64, 1, 'str_word_count_pos_'.$id);
        $countSlot = $context->builder->alloca($i64, 1, 'str_word_count_n_'.$id);
        $inWordSlot = $context->builder->alloca($i8, 1, 'str_word_count_in_'.$id);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $countSlot);
        $context->builder->store($i8->constInt(0, false), $inWordSlot);

        $head = BasicBlockHelper::append($context, 'str_word_count_head_'.$id);
        $body = BasicBlockHelper::append($context, 'str_word_count_body_'.$id);
        $done = BasicBlockHelper::append($context, 'str_word_count_done_'.$id);

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $len);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $chPtr = $context->builder->inBoundsGEP($data, $pos);
        $ch = $context->builder->load($chPtr);
        $chI64 = $context->builder->zExt($ch, $i64);
        $inWord = $context->builder->load($inWordSlot);
        $inWordI64 = $context->builder->zExt($inWord, $i64);

        $isLetter = self::isLetter($context, $chI64);
        $isApostrophe = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(39, false));
        $isHyphen = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(45, false));
        $inWordBool = $context->builder->icmp(Builder::INT_NE, $inWordI64, $zero);
        $innerPunct = $context->builder->or(
            $context->builder->and($inWordBool, $isApostrophe),
            $context->builder->and($inWordBool, $isHyphen)
        );
        $isWordChar = $context->builder->or($isLetter, $innerPunct);

        $wasInWord = $inWordBool;
        $context->builder->store(
            $context->builder->zExt($isWordChar, $i8),
            $inWordSlot
        );

        $startWord = $context->builder->and(
            $isWordChar,
            $context->builder->not($wasInWord)
        );
        $count = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $count,
                $context->builder->zExt($startWord, $i64)
            ),
            $countSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $one),
            $posSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function isLetter(Context $context, Value $ord): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(90, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(122, false))
        );

        return $context->builder->or($isUpper, $isLower);
    }
}
