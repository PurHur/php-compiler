<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM for {@see \PHPCompiler\ext\standard\ArraySumJitHelper::sum} (#24167).
 *
 * Emits the fold inline into the caller (not a NestedJIT ABI that returns a
 * {@see \PHPCompiler\VM\Variable} value-box — that path writeObject'd the Variable
 * and/or returned a dangling alloca under thin standalone AOT; peer ArrayShift #24025
 * / unserialize #20785).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\ArraySumJitHelper}.
 * php-src: ext/standard/array.c — php_array_sum_or_product
 */
final class ArraySumLlvm
{
    private static int $seq = 0;

    /**
     * Sum packed hashtable elements into a caller-frame {@see __value__} box
     * (int or float tag). Non-numeric / enum / object elements contribute 0 or
     * are skipped per php-src (#4278 / #5578) for the types this LLVM slice handles.
     */
    public static function sum(Context $context, Value $ht): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_sum_llvm_cont');
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $sumIntSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $sumFloatSlot = BasicBlockHelper::entryAlloca($context, $double);
        $useFloatSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_sum_llvm_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_sum_llvm_body_'.$tag);
        $longBb = BasicBlockHelper::append($context, 'array_sum_llvm_long_'.$tag);
        $afterLong = BasicBlockHelper::append($context, 'array_sum_llvm_after_long_'.$tag);
        $doubleBb = BasicBlockHelper::append($context, 'array_sum_llvm_double_'.$tag);
        $afterDouble = BasicBlockHelper::append($context, 'array_sum_llvm_after_double_'.$tag);
        $boolBb = BasicBlockHelper::append($context, 'array_sum_llvm_bool_'.$tag);
        $continueBb = BasicBlockHelper::append($context, 'array_sum_llvm_cont_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'array_sum_llvm_done_'.$tag);
        $intResultBb = BasicBlockHelper::append($context, 'array_sum_llvm_int_out_'.$tag);
        $floatResultBb = BasicBlockHelper::append($context, 'array_sum_llvm_float_out_'.$tag);
        $retBb = BasicBlockHelper::append($context, 'array_sum_llvm_ret_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $doneBb, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $doneBb, $body);

        $context->builder->positionAtEnd($body);
        $entry = HashTableReadLlvm::listEntryPointer($context, $ht, $idx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBb, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBb, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBb, $continueBb);

        $context->builder->positionAtEnd($longBb);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::accumulateLong($context, $longVal, $sumIntSlot, $sumFloatSlot, $useFloatSlot, 'l'.$tag);
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($boolBb);
        $boolLong = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        self::accumulateLong($context, $boolLong, $sumIntSlot, $sumFloatSlot, $useFloatSlot, 'b'.$tag);
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($doubleBb);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBb = BasicBlockHelper::append($context, 'array_sum_llvm_promote_'.$tag);
        $addFloatBb = BasicBlockHelper::append($context, 'array_sum_llvm_addf_'.$tag);
        $doubleDone = BasicBlockHelper::append($context, 'array_sum_llvm_df_done_'.$tag);
        $context->builder->branchIf($useFloatNow, $addFloatBb, $promoteBb);

        $context->builder->positionAtEnd($promoteBb);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->siToFp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBb);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBb);

        $context->builder->positionAtEnd($continueBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBb);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $useFloat = $context->builder->load($useFloatSlot);
        $context->builder->branchIf($useFloat, $floatResultBb, $intResultBb);

        $context->builder->positionAtEnd($intResultBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->builder->load($sumIntSlot)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($floatResultBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $resultPtr,
            $context->builder->load($sumFloatSlot)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);

        return $resultSlot;
    }

    private static function accumulateLong(
        Context $context,
        Value $longVal,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $double = $context->getTypeFromString('double');
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_sum_llvm_acc_f_'.$tag);
        $intPath = BasicBlockHelper::append($context, 'array_sum_llvm_acc_i_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_sum_llvm_acc_d_'.$tag);
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatPath);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->siToFp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
