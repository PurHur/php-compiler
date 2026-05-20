<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for str_split() — packed __hashtable__ of string chunks.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrSplit
{
    private static int $emitSeq = 0;

    private static function nextEmitId(): string
    {
        return (string) (++self::$emitSeq);
    }

    /**
     * Emit LLVM for str_split on a compile-time string (unrolled slices, same as explode parts).
     */
    public static function buildPackedStrings(Context $context, string $literal, int $chunkLen): Value
    {
        $emitId = self::nextEmitId();
        $parts = VmString::strSplit($literal, $chunkLen);
        $full = $context->builder->load($context->constantStringFromString($literal));
        $map = $context->structFieldMap['__string__'];
        $hayPtr = $context->builder->structGep($full, $map['value']);
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $offset = $i64->constInt(0, false);
        foreach ($parts as $i => $part) {
            $take = $i64->constInt(\strlen($part), false);
            $slice = string_trim::jitCopySlice(
                $context,
                $full,
                $hayPtr,
                $offset,
                $take,
                'ct'.$emitId.'_'.$i
            );
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
            $offset = $context->builder->add($offset, $take);
        }

        return $ht;
    }

    public static function compileTimeLong(Context $context, JITVariable $var): int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type
            || JITVariable::KIND_VALUE !== $var->kind) {
            throw new \LogicException('str_split() length must be a compile-time integer in this compiler build');
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        throw new \LogicException('str_split() length must be a compile-time integer in this compiler build');
    }

    public static function split(Context $context, Value $string, Value $chunkLen): Value
    {
        $emitId = self::nextEmitId();
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($string, $map['length'])
        );
        $hayPtr = $context->builder->structGep($string, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $sizeOne = $sizeT->constInt(1, false);

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $offsetSlot = $context->builder->alloca($i64, 1, 'strsplit_offset_'.$emitId);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'strsplit_idx_'.$emitId);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'strsplit_head_'.$emitId);
        $loopBody = BasicBlockHelper::append($context, 'strsplit_body_'.$emitId);
        $doneBlock = BasicBlockHelper::append($context, 'strsplit_done_'.$emitId);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $offset, $hayLen);
        $context->builder->branchIf($stop, $doneBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $remaining = $context->builder->sub($hayLen, $offset);
        $endPos = $context->builder->add($offset, $chunkLen);
        $needsTrim = $context->builder->icmp(Builder::INT_SGT, $endPos, $hayLen);
        $take = $context->builder->select($needsTrim, $remaining, $chunkLen);
        $part = string_trim::jitCopySlice($context, $string, $hayPtr, $offset, $take, 'ss'.$emitId);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeOne),
            $idxSlot
        );
        $context->builder->store(
            $context->builder->add($offset, $chunkLen),
            $offsetSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($doneBlock);

        return $ht;
    }
}
