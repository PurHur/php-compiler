<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT for shuffle() — call-site Fisher–Yates (NestedJIT ShuffleJitHelper is a no-op
 * under thin standalone AOT: writeHashtable + writeNull, #36397 slice 12).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::shufflePacked()}.
 * php-src: ext/standard/array.c — php_shuffle / SEPARATE_ARRAY
 */
final class ShuffleRuntime
{
    private static int $seq = 0;

    public static function shufflePacked(Context $context, JITVariable $array): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'shuffle_llvm_cont');
        StringRandomBytes::ensureLinked($context);
        // By-ref mutator: SEPARATE_ARRAY before in-place shuffle (php-src php_shuffle / #36397).
        $ht = HashTableHelper::separateContainerForWrite($context, $array);
        Refcount::emitAssertExclusiveCall($context, $ht);
        self::emitFisherYatesPacked($context, $ht);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    public static function ensureLinked(Context $context): void
    {
        // Call-site emission — CSPRNG ABI only (#36397 slice 12).
        StringRandomBytes::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * In-place Fisher–Yates on packed `__value__` slots (list shape).
     * Assoc reindex (php-src) stays on the VM path via {@see VmArray::shufflePacked}.
     */
    private static function emitFisherYatesPacked(Context $context, Value $ht): void
    {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $valueType = $context->getTypeFromString('__value__');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);

        $n = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $done = BasicBlockHelper::append($context, 'shuffle_fy_done_'.$tag);
        $work = BasicBlockHelper::append($context, 'shuffle_fy_work_'.$tag);
        $tooSmall = $context->builder->icmp(Builder::INT_ULT, $n, $two);
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        // i from n-1 down to 1: swap a[i] with a[random(0..i)]
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($context->builder->subNoSignedWrap($n, $one), $iSlot);
        $loopHead = BasicBlockHelper::append($context, 'shuffle_fy_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'shuffle_fy_body_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_EQ, $i, $zero);
        $context->builder->branchIf($stop, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $upper = $context->builder->addNoSignedWrap($i, $one);
        $j = self::emitRandomIndexBelow($context, $upper, $tag);
        $slotI = HashTableReadLlvm::listEntryPointer($context, $ht, $i);
        $slotJ = HashTableReadLlvm::listEntryPointer($context, $ht, $j);
        $tmp = BasicBlockHelper::entryAlloca($context, $valueType);
        $context->builder->store($context->builder->load($slotI), $tmp);
        $context->builder->store($context->builder->load($slotJ), $slotI);
        $context->builder->store($context->builder->load($tmp), $slotJ);
        $context->builder->store($context->builder->subNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    /** Uniform index in [0, $upperExclusive) using 8 CSPRNG bytes (peer ArrayRandLlvm). */
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
        $randPtr = $context->builder->structGep($randStr, $randMap['value']);

        $accSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $accSlot);
        $byteSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $head = BasicBlockHelper::append($context, 'shuffle_rnd_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'shuffle_rnd_body_'.$tag);
        $rndDone = BasicBlockHelper::append($context, 'shuffle_rnd_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $bi = $context->builder->load($byteSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $bi, $i64->constInt(8, false));
        $context->builder->branchIf($stop, $rndDone, $body);

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

        $context->builder->positionAtEnd($rndDone);
        $accVal = $context->builder->load($accSlot);
        $upperI64 = JitNestedHelperCoerce::scalarToI64(
            $context,
            $upperExclusive,
            $upperExclusive->typeOf()
        );
        $rem = $context->builder->unsigendRem($accVal, $upperI64);

        return $context->builder->truncOrBitCast($rem, $sizeT);
    }
}
