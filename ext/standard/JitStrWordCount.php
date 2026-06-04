<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for str_word_count() (all formats; issue #2382, #3584, #5516).
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

        $extraSlot = self::allocExtraMask($context, null);
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
        $isWordChar = self::isWordChar($context, $chI64, $inWord, $extraSlot);
        $wasInWord = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($inWord, $i64),
            $zero
        );
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

    /**
     * Build word list (format 1) or offset map (format 2) at JIT time.
     */
    public static function wordHashTable(
        Context $context,
        Value $str,
        Value $format,
        ?Value $chars
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $data = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $ht = HashTableHelper::alloc($context);
        $extraSlot = self::allocExtraMask($context, $chars);
        $id = (string) (++self::$blockSerial);
        $posSlot = $context->builder->alloca($i64, 1, 'swc_pos_'.$id);
        $wordStartSlot = $context->builder->alloca($i64, 1, 'swc_ws_'.$id);
        $inWordSlot = $context->builder->alloca($i8, 1, 'swc_in_'.$id);
        $listIdxSlot = $context->builder->alloca($sizeT, 1, 'swc_li_'.$id);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $wordStartSlot);
        $context->builder->store($i8->constInt(0, false), $inWordSlot);
        $context->builder->store($sizeT->constInt(0, false), $listIdxSlot);

        $head = BasicBlockHelper::append($context, 'swc_head_'.$id);
        $body = BasicBlockHelper::append($context, 'swc_body_'.$id);
        $flush = BasicBlockHelper::append($context, 'swc_flush_'.$id);
        $advance = BasicBlockHelper::append($context, 'swc_adv_'.$id);
        $done = BasicBlockHelper::append($context, 'swc_done_'.$id);

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
        $isWordChar = self::isWordChar($context, $chI64, $inWord, $extraSlot);
        $wasInWord = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($inWord, $i64),
            $zero
        );
        $endWord = $context->builder->and(
            $context->builder->not($isWordChar),
            $wasInWord
        );
        $context->builder->branchIf($endWord, $flush, $advance);

        $context->builder->positionAtEnd($flush);
        self::appendWord(
            $context,
            $ht,
            $str,
            $context->builder->load($wordStartSlot),
            $pos,
            $format,
            $listIdxSlot,
            $wordStartSlot
        );
        $context->builder->store($i8->constInt(0, false), $inWordSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $startWord = $context->builder->and(
            $isWordChar,
            $context->builder->not($wasInWord)
        );
        $startBlock = BasicBlockHelper::append($context, 'swc_start_'.$id);
        $afterStart = BasicBlockHelper::append($context, 'swc_after_start_'.$id);
        $context->builder->branchIf($startWord, $startBlock, $afterStart);
        $context->builder->positionAtEnd($startBlock);
        $context->builder->store($pos, $wordStartSlot);
        $context->builder->store($i8->constInt(1, false), $inWordSlot);
        $context->builder->branch($afterStart);
        $context->builder->positionAtEnd($afterStart);
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $one),
            $posSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $inWordEnd = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($context->builder->load($inWordSlot), $i64),
            $zero
        );
        $finalFlush = BasicBlockHelper::append($context, 'swc_final_flush_'.$id);
        $finalDone = BasicBlockHelper::append($context, 'swc_final_done_'.$id);
        $context->builder->branchIf($inWordEnd, $finalFlush, $finalDone);
        $context->builder->positionAtEnd($finalFlush);
        self::appendWord(
            $context,
            $ht,
            $str,
            $context->builder->load($wordStartSlot),
            $len,
            $format,
            $listIdxSlot,
            $wordStartSlot
        );
        $context->builder->branch($finalDone);
        $context->builder->positionAtEnd($finalDone);

        return $ht;
    }

    private static function appendWord(
        Context $context,
        Value $ht,
        Value $str,
        Value $wordStart,
        Value $wordEnd,
        Value $format,
        Value $listIdxSlot,
        Value $_wordStartSlot
    ): void {
        $map = $context->structFieldMap['__string__'];
        $data = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i64->constInt(1, false);
        $wordLen = $context->builder->subNoSignedWrap($wordEnd, $wordStart);
        $slicePtr = $context->builder->inBoundsGEP($data, $wordStart);
        $sliceCast = $context->builder->pointerCast($slicePtr, $i8p);
        $word = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $wordLen,
            $sliceCast
        );
        $isFmt1 = $context->builder->icmp(Builder::INT_EQ, $format, $one);
        $listIdx = $context->builder->load($listIdxSlot);
        $offsetIdx = $context->builder->truncOrBitCast($wordStart, $sizeT);
        $index = $context->builder->select($isFmt1, $listIdx, $offsetIdx);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $index,
            $word
        );
        $nextList = $context->builder->addNoSignedWrap($listIdx, $sizeT->constInt(1, false));
        $context->builder->store(
            $context->builder->select($isFmt1, $nextList, $listIdx),
            $listIdxSlot
        );
    }

    /**
     * @return Value alloca i8[256] extra-char bitmask
     */
    private static function allocExtraMask(Context $context, ?Value $chars): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i8->constInt(0, false);
        $extra = $context->builder->alloca($i8, 256, 'str_word_count_extra');
        $id = (string) (++self::$blockSerial);
        $iSlot = $context->builder->alloca($i64, 1, 'swc_extra_i_'.$id);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $initHead = BasicBlockHelper::append($context, 'swc_extra_init_head_'.$id);
        $initBody = BasicBlockHelper::append($context, 'swc_extra_init_body_'.$id);
        $initDone = BasicBlockHelper::append($context, 'swc_extra_init_done_'.$id);
        $context->builder->branch($initHead);
        $context->builder->positionAtEnd($initHead);
        $i = $context->builder->load($iSlot);
        $at256 = $context->builder->icmp(Builder::INT_SGE, $i, $i64->constInt(256, false));
        $context->builder->branchIf($at256, $initDone, $initBody);
        $context->builder->positionAtEnd($initBody);
        $context->builder->store($zero, $context->builder->inBoundsGEP($extra, $i));
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($initHead);
        $context->builder->positionAtEnd($initDone);

        if (null === $chars) {
            return $extra;
        }

        $map = $context->structFieldMap['__string__'];
        $clen = $context->builder->load(
            $context->builder->structGep($chars, $map['length'])
        );
        $cdata = $context->builder->structGep($chars, $map['value']);
        $jSlot = $context->builder->alloca($i64, 1, 'swc_extra_j_'.$id);
        $context->builder->store($i64->constInt(0, false), $jSlot);
        $fillHead = BasicBlockHelper::append($context, 'swc_extra_fill_head_'.$id);
        $fillBody = BasicBlockHelper::append($context, 'swc_extra_fill_body_'.$id);
        $fillDone = BasicBlockHelper::append($context, 'swc_extra_fill_done_'.$id);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $j = $context->builder->load($jSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $j, $clen);
        $context->builder->branchIf($past, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $c = $context->builder->load($context->builder->inBoundsGEP($cdata, $j));
        $cI64 = $context->builder->zExt($c, $i64);
        $context->builder->store(
            $i8->constInt(1, false),
            $context->builder->inBoundsGEP($extra, $cI64)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($j, $i64->constInt(1, false)),
            $jSlot
        );
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);

        return $extra;
    }

    private static function isWordChar(
        Context $context,
        Value $chI64,
        Value $inWord,
        Value $extraSlot
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isLetter = self::isLetter($context, $chI64);
        $extraByte = $context->builder->load(
            $context->builder->inBoundsGEP($extraSlot, $chI64)
        );
        $hasExtra = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($extraByte, $i64),
            $zero
        );
        $isApostrophe = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(39, false));
        $isHyphen = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(45, false));
        $inWordBool = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($inWord, $i64),
            $zero
        );
        $innerPunct = $context->builder->or(
            $context->builder->and($inWordBool, $isApostrophe),
            $context->builder->and($inWordBool, $isHyphen)
        );

        return $context->builder->or($isLetter, $context->builder->or($hasExtra, $innerPunct));
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

    /**
     * Build a compile-time __hashtable__ from VM word list / offset map (formats 1 and 2).
     */
    public static function hashTableFromVmResult(Context $context, array $result, int $format): Value
    {
        $ht = new HashTable();
        if (1 === $format) {
            foreach ($result as $word) {
                $value = new Variable();
                $value->string($word);
                $ht->append($value);
            }
        } else {
            foreach ($result as $pos => $word) {
                $value = new Variable();
                $value->string($word);
                $ht->addIndex((int) $pos, $value);
            }
        }
        $jit = HashTableHelper::variableFromVmHashTable($context, $ht);

        return $jit->value;
    }

    public static function compileTimeFormat(JITVariable $arg): int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            || JITVariable::KIND_VALUE !== $arg->kind) {
            throw new \LogicException('str_word_count() format must be a compile-time integer in this compiler build');
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        throw new \LogicException('str_word_count() format must be a compile-time integer in this compiler build');
    }

    public static function jitFormatArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        return JitLongArg::lower($context, $arg, 'str_word_count() argument #2 ($format)');
    }
}
