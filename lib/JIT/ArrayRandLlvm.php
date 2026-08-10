<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\ArrayRandJitHelper::pick} (#27547).
 *
 * NestedJIT of ArrayRandJitHelper → {@see \PHPCompiler\ext\standard\VmArray::arrayRandPacked}
 * returned a dangling {@see \PHPCompiler\VM\Variable} alloca under thin standalone AOT
 * (silent NULL; peer ArrayProduct #26968 / ArraySearch #27133 / ArraySum #24167).
 *
 * Packed-list keys are numeric indices (Zend ordered-list shape), matching the
 * pre-#16135 {@see __hashtable__arrayRandPacked} path. Assoc/string-key tables stay
 * correct on the VM path via {@see ArrayRandJitHelper}.
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::arrayRandPacked()}.
 * php-src: ext/standard/array.c — php_array_rand / php_array_pick_keys
 */
final class ArrayRandLlvm
{
    private const EMPTY_ERROR = 'array_rand(): Argument #1 ($array) must not be empty';

    private const NUM_RANGE_ERROR = 'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)';

    private static int $seq = 0;

    /**
     * Pick {@param $num} packed keys into a caller-frame {@see __value__} box
     * (int key when num=1, hashtable of int keys when num>1).
     */
    public static function pick(Context $context, Value $ht, Value $num): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_rand_llvm_cont');
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $one64 = $i64->constInt(1, false);

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        StringRandomBytes::ensureLinked($context);

        $n = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $n64 = JitNestedHelperCoerce::scalarToI64($context, $n, $sizeT);
        $num64 = JitNestedHelperCoerce::scalarToI64($context, $num, $num->typeOf());
        $numSz = $context->builder->truncOrBitCast($num64, $sizeT);

        $notEmpty = $context->builder->icmp(Builder::INT_NE, $n, $zero);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $notEmpty,
            'array_rand_llvm_empty_'.$tag,
            self::EMPTY_ERROR
        );

        $numOkLow = $context->builder->icmp(Builder::INT_SGE, $num64, $one64);
        $numOkHigh = $context->builder->icmp(Builder::INT_SLE, $num64, $n64);
        $numOk = $context->builder->and($numOkLow, $numOkHigh);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $numOk,
            'array_rand_llvm_num_'.$tag,
            self::NUM_RANGE_ERROR
        );

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $isSingle = $context->builder->icmp(Builder::INT_EQ, $num64, $one64);
        $singleBb = BasicBlockHelper::append($context, 'array_rand_llvm_single_'.$tag);
        $multiBb = BasicBlockHelper::append($context, 'array_rand_llvm_multi_'.$tag);
        $retBb = BasicBlockHelper::append($context, 'array_rand_llvm_ret_'.$tag);
        $context->builder->branchIf($isSingle, $singleBb, $multiBb);

        $context->builder->positionAtEnd($singleBb);
        $idx = self::emitRandomIndexBelow($context, $n, $tag.'_s');
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->builder->truncOrBitCast(
                JitNestedHelperCoerce::scalarToI64($context, $idx, $sizeT),
                $i64
            )
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($multiBb);
        self::emitMultiPick($context, $n, $numSz, $resultPtr, $tag);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);

        return $resultSlot;
    }

    /** Partial Fisher–Yates; writes first {@param $num} indices as a packed int list. */
    private static function emitMultiPick(
        Context $context,
        Value $n,
        Value $num,
        Value $resultPtr,
        string $tag
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $sizeOfSizeT = $sizeT->constInt(8, false);

        $bytes = $context->builder->mulNoSignedWrap($n, $sizeOfSizeT);
        $raw = $context->builder->call($context->lookupFunction('__mm__malloc'), $bytes);
        $indices = $context->builder->pointerCast($raw, $context->getTypeFromString('size_t*'));

        $initSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $initSlot);
        $initHead = BasicBlockHelper::append($context, 'array_rand_llvm_init_head_'.$tag);
        $initBody = BasicBlockHelper::append($context, 'array_rand_llvm_init_body_'.$tag);
        $initDone = BasicBlockHelper::append($context, 'array_rand_llvm_init_done_'.$tag);
        $context->builder->branch($initHead);

        $context->builder->positionAtEnd($initHead);
        $initI = $context->builder->load($initSlot);
        $initStop = $context->builder->icmp(Builder::INT_UGE, $initI, $n);
        $context->builder->branchIf($initStop, $initDone, $initBody);

        $context->builder->positionAtEnd($initBody);
        $context->builder->store($initI, $context->builder->inBoundsGep($indices, $initI));
        $context->builder->store($context->builder->addNoSignedWrap($initI, $one), $initSlot);
        $context->builder->branch($initHead);

        $context->builder->positionAtEnd($initDone);
        $pickSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $pickSlot);
        $pickHead = BasicBlockHelper::append($context, 'array_rand_llvm_fy_head_'.$tag);
        $pickRand = BasicBlockHelper::append($context, 'array_rand_llvm_fy_rand_'.$tag);
        $pickDone = BasicBlockHelper::append($context, 'array_rand_llvm_fy_done_'.$tag);
        $context->builder->branch($pickHead);

        $context->builder->positionAtEnd($pickHead);
        $pickI = $context->builder->load($pickSlot);
        $pickStop = $context->builder->icmp(Builder::INT_UGE, $pickI, $num);
        $context->builder->branchIf($pickStop, $pickDone, $pickRand);

        $context->builder->positionAtEnd($pickRand);
        $upper = $context->builder->sub($n, $pickI);
        $offset = self::emitRandomIndexBelow($context, $upper, $tag.'_fy');
        $j = $context->builder->addNoSignedWrap($pickI, $offset);
        $slotA = $context->builder->inBoundsGep($indices, $pickI);
        $slotB = $context->builder->inBoundsGep($indices, $j);
        $a = $context->builder->load($slotA);
        $b = $context->builder->load($slotB);
        $context->builder->store($b, $slotA);
        $context->builder->store($a, $slotB);
        $context->builder->store($context->builder->addNoSignedWrap($pickI, $one), $pickSlot);
        $context->builder->branch($pickHead);

        $context->builder->positionAtEnd($pickDone);
        $dest = HashTableHelper::alloc($context);
        $outSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $outSlot);
        $outHead = BasicBlockHelper::append($context, 'array_rand_llvm_out_head_'.$tag);
        $outBody = BasicBlockHelper::append($context, 'array_rand_llvm_out_body_'.$tag);
        $outDone = BasicBlockHelper::append($context, 'array_rand_llvm_out_done_'.$tag);
        $context->builder->branch($outHead);

        $context->builder->positionAtEnd($outHead);
        $outI = $context->builder->load($outSlot);
        $outStop = $context->builder->icmp(Builder::INT_UGE, $outI, $num);
        $context->builder->branchIf($outStop, $outDone, $outBody);

        $context->builder->positionAtEnd($outBody);
        $key = $context->builder->load($context->builder->inBoundsGep($indices, $outI));
        $keyVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->truncOrBitCast(
                JitNestedHelperCoerce::scalarToI64($context, $key, $sizeT),
                $i64
            )
        );
        HashTableHelper::setAtIndex($context, $dest, $outI, $keyVar);
        $context->builder->store($context->builder->addNoSignedWrap($outI, $one), $outSlot);
        $context->builder->branch($outHead);

        $context->builder->positionAtEnd($outDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $dest
        );
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->pointerCast($indices, $i8p)
        );
    }

    /** Uniform index in [0, $upperExclusive) using 8 CSPRNG bytes (pre-#16135 shape). */
    private static function emitRandomIndexBelow(
        Context $context,
        Value $upperExclusive,
        string $tag
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $randStr = $context->builder->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $i64->constInt(8, false)
        );
        $randMap = $context->structFieldMap['__string__'];
        // Pointer to the first byte of the string payload (same as pre-#16135 HashTable).
        $randPtr = $context->builder->structGep($randStr, $randMap['value']);

        $accSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $accSlot);
        $byteSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $head = BasicBlockHelper::append($context, 'array_rand_llvm_rnd_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_rand_llvm_rnd_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_rand_llvm_rnd_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $bi = $context->builder->load($byteSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $bi, $i64->constInt(8, false));
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $acc = $context->builder->load($accSlot);
        $byte = $context->builder->zExt(
            $context->builder->load($context->builder->gep($randPtr, $bi)),
            $i64
        );
        $shifted = $context->builder->shl($acc, $i64->constInt(8, false));
        $context->builder->store($context->builder->or($shifted, $byte), $accSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($bi, $i64->constInt(1, false)),
            $byteSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accVal = $context->builder->load($accSlot);
        $upperI64 = JitNestedHelperCoerce::scalarToI64($context, $upperExclusive, $upperExclusive->typeOf());
        $rem = $context->builder->unsigendRem($accVal, $upperI64);

        return $context->builder->truncOrBitCast($rem, $sizeT);
    }
}
