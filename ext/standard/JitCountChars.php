<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT lowering for count_chars() (PHP 8 modes 0–4; ext/standard/string.c).
 */
final class JitCountChars
{
    private static int $blockSerial = 0;

    /**
     * @param array<int, int> $histogram
     */
    public static function materializeHistogram(Context $context, array $histogram): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $grow = $context->lookupFunction('__hashtable__grow');
        $oneSizeT = $sizeT->constInt(1, false);
        foreach ($histogram as $byte => $count) {
            $ord = $context->builder->truncOrBitCast(
                $i64->constInt((int) $byte, false),
                $sizeT
            );
            $need = $context->builder->addNoSignedWrap($ord, $oneSizeT);
            $context->builder->call($grow, $ht, $need);
            $context->builder->call(
                $setLong,
                $ht,
                $ord,
                $i64->constInt((int) $count, false)
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->refcount->addref($ht);

        return $ptr;
    }

    public static function materializeByteString(Context $context, string $bytes): Value
    {
        $len = \strlen($bytes);
        $i64 = $context->getTypeFromString('int64');
        $str = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt($len, false)
        );
        if ($len > 0) {
            $map = $context->structFieldMap['__string__'];
            $dest = $context->builder->structGep($str, $map['value']);
            $i8 = $context->getTypeFromString('int8');
            for ($i = 0; $i < $len; ++$i) {
                $chPtr = $context->builder->inBoundsGEP($dest, $i64->constInt($i, false));
                $context->builder->store($i8->constInt(\ord($bytes[$i]), false), $chPtr);
            }
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    public static function compileTimeMode(Context $context, JITVariable $var): int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type
            || JITVariable::KIND_VALUE !== $var->kind) {
            throw new \LogicException(
                'count_chars() argument #2 must be a compile-time integer in this compiler build'
            );
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        throw new \LogicException(
            'count_chars() argument #2 must be a compile-time integer in this compiler build'
        );
    }

    public static function invoke(Context $context, Value $str, int $mode): Value
    {
        if ($mode < 0 || $mode > 4) {
            throw new \LogicException(
                'count_chars(): Argument #2 ($mode) must be between 0 and 4 (inclusive)'
            );
        }

        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $data = $context->builder->structGep($str, $map['value']);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $countsSlot = $context->builder->alloca($i64, 256, 'count_chars_hist');
        for ($b = 0; $b < 256; ++$b) {
            $context->builder->store($zero, $context->builder->inBoundsGEP($countsSlot, $i64->constInt($b, false)));
        }
        self::histogramLoop($context, $data, $len, $countsSlot, $zero, $one, $i64, $i8);

        if ($mode >= 3) {
            return self::emitStringMode($context, $countsSlot, $mode, $i64, $i8);
        }

        return self::emitArrayMode($context, $countsSlot, $mode, $i64, $sizeT);
    }

    private static function histogramLoop(
        Context $context,
        Value $data,
        Value $len,
        Value $countsSlot,
        Value $zero,
        Value $one,
        $i64,
        $i8
    ): void {
        $id = (string) (++self::$blockSerial);
        $posSlot = $context->builder->alloca($i64, 1, 'count_chars_pos_'.$id);
        $context->builder->store($zero, $posSlot);

        $head = BasicBlockHelper::append($context, 'count_chars_hist_head_'.$id);
        $body = BasicBlockHelper::append($context, 'count_chars_hist_body_'.$id);
        $done = BasicBlockHelper::append($context, 'count_chars_hist_done_'.$id);

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $len);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($data, $pos));
        $ord = $context->builder->zExt($ch, $i64);
        $entry = $context->builder->inBoundsGEP($countsSlot, $ord);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($entry), $one),
            $entry
        );
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function emitArrayMode(
        Context $context,
        Value $countsSlot,
        int $mode,
        $i64,
        $sizeT
    ): Value {
        $ht = HashTableHelper::alloc($context);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $grow = $context->lookupFunction('__hashtable__grow');
        $oneSizeT = $sizeT->constInt(1, false);
        $byte256 = $i64->constInt(256, false);

        $id = (string) (++self::$blockSerial);
        $byteSlot = $context->builder->alloca($i64, 1, 'count_chars_byte_'.$id);
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $head = BasicBlockHelper::append($context, 'count_chars_arr_head_'.$id);
        $body = BasicBlockHelper::append($context, 'count_chars_arr_body_'.$id);
        $done = BasicBlockHelper::append($context, 'count_chars_arr_done_'.$id);

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $byte = $context->builder->load($byteSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $byte, $byte256);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $ord = $context->builder->truncOrBitCast($byte, $sizeT);
        $count = $context->builder->load($context->builder->inBoundsGEP($countsSlot, $byte));
        $store = true;
        if (1 === $mode) {
            $store = $context->builder->icmp(Builder::INT_SGT, $count, $i64->constInt(0, false));
        } elseif (2 === $mode) {
            $store = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        }
        if (1 === $mode || 2 === $mode) {
            $storeBb = BasicBlockHelper::append($context, 'count_chars_arr_store_'.$id);
            $skipBb = BasicBlockHelper::append($context, 'count_chars_arr_skip_'.$id);
            $context->builder->branchIf($store, $storeBb, $skipBb);
            $context->builder->positionAtEnd($storeBb);
            $need = $context->builder->addNoSignedWrap($ord, $oneSizeT);
            $context->builder->call($grow, $ht, $need);
            $val = 2 === $mode ? $i64->constInt(0, false) : $count;
            $context->builder->call($setLong, $ht, $ord, $val);
            $context->builder->branch($skipBb);
            $context->builder->positionAtEnd($skipBb);
        } else {
            $need = $context->builder->addNoSignedWrap($ord, $oneSizeT);
            $context->builder->call($grow, $ht, $need);
            $context->builder->call($setLong, $ht, $ord, $count);
        }
        $context->builder->store($context->builder->addNoSignedWrap($byte, $i64->constInt(1, false)), $byteSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->refcount->addref($ht);

        return $ptr;
    }

    private static function emitStringMode(
        Context $context,
        Value $countsSlot,
        int $mode,
        $i64,
        $i8
    ): Value {
        $outLenSlot = $context->builder->alloca($i64, 1, 'count_chars_out_len');
        $context->builder->store($i64->constInt(0, false), $outLenSlot);
        $byte256 = $i64->constInt(256, false);
        $id = (string) (++self::$blockSerial);
        $byteSlot = $context->builder->alloca($i64, 1, 'count_chars_sbyte_'.$id);
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $head = BasicBlockHelper::append($context, 'count_chars_str_len_head_'.$id);
        $body = BasicBlockHelper::append($context, 'count_chars_str_len_body_'.$id);
        $done = BasicBlockHelper::append($context, 'count_chars_str_len_done_'.$id);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $byte = $context->builder->load($byteSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $byte, $byte256);
        $context->builder->branchIf($past, $done, $body);
        $context->builder->positionAtEnd($body);
        $count = $context->builder->load($context->builder->inBoundsGEP($countsSlot, $byte));
        $want = 3 === $mode
            ? $context->builder->icmp(Builder::INT_SGT, $count, $i64->constInt(0, false))
            : $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        $incBb = BasicBlockHelper::append($context, 'count_chars_str_len_inc_'.$id);
        $skipBb = BasicBlockHelper::append($context, 'count_chars_str_len_skip_'.$id);
        $context->builder->branchIf($want, $incBb, $skipBb);
        $context->builder->positionAtEnd($incBb);
        $len = $context->builder->load($outLenSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($len, $i64->constInt(1, false)),
            $outLenSlot
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($byte, $i64->constInt(1, false)), $byteSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        $outLen = $context->builder->load($outLenSlot);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $map = $context->structFieldMap['__string__'];
        $dest = $context->builder->structGep($str, $map['value']);
        $writePosSlot = $context->builder->alloca($i64, 1, 'count_chars_write_pos');
        $context->builder->store($i64->constInt(0, false), $writePosSlot);

        $id2 = (string) (++self::$blockSerial);
        $byteSlot2 = $context->builder->alloca($i64, 1, 'count_chars_sbyte2_'.$id2);
        $context->builder->store($i64->constInt(0, false), $byteSlot2);
        $head2 = BasicBlockHelper::append($context, 'count_chars_str_fill_head_'.$id2);
        $body2 = BasicBlockHelper::append($context, 'count_chars_str_fill_body_'.$id2);
        $done2 = BasicBlockHelper::append($context, 'count_chars_str_fill_done_'.$id2);
        $context->builder->branch($head2);
        $context->builder->positionAtEnd($head2);
        $byte2 = $context->builder->load($byteSlot2);
        $past2 = $context->builder->icmp(Builder::INT_SGE, $byte2, $byte256);
        $context->builder->branchIf($past2, $done2, $body2);
        $context->builder->positionAtEnd($body2);
        $count2 = $context->builder->load($context->builder->inBoundsGEP($countsSlot, $byte2));
        $want2 = 3 === $mode
            ? $context->builder->icmp(Builder::INT_SGT, $count2, $i64->constInt(0, false))
            : $context->builder->icmp(Builder::INT_EQ, $count2, $i64->constInt(0, false));
        $writeBb = BasicBlockHelper::append($context, 'count_chars_str_fill_write_'.$id2);
        $skipBb2 = BasicBlockHelper::append($context, 'count_chars_str_fill_skip_'.$id2);
        $context->builder->branchIf($want2, $writeBb, $skipBb2);
        $context->builder->positionAtEnd($writeBb);
        $pos = $context->builder->load($writePosSlot);
        $context->builder->store(
            $context->builder->truncOrBitCast($byte2, $i8),
            $context->builder->inBoundsGEP($dest, $pos)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false)),
            $writePosSlot
        );
        $context->builder->branch($skipBb2);
        $context->builder->positionAtEnd($skipBb2);
        $context->builder->store($context->builder->addNoSignedWrap($byte2, $i64->constInt(1, false)), $byteSlot2);
        $context->builder->branch($head2);
        $context->builder->positionAtEnd($done2);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }
}
