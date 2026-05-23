<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for chunk_split(). */
final class JitChunkSplit
{
    public static function split(
        Context $context,
        Value $input,
        Value $chunkLen,
        Value $separator
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $inLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inPtr = $context->builder->structGep($input, $map['value']);
        $sepLen = $context->builder->load(
            $context->builder->structGep($separator, $map['length'])
        );
        $sepPtr = $context->builder->structGep($separator, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $doneBlock = BasicBlockHelper::append($context, 'chunksplit_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $inLen, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'chunksplit_empty');
        $workBlock = BasicBlockHelper::append($context, 'chunksplit_work');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $numChunks = $context->builder->unsignedDiv(
            $context->builder->add(
                $context->builder->sub($inLen, $one),
                $chunkLen
            ),
            $chunkLen
        );
        $outLen = $context->builder->add(
            $inLen,
            $context->builder->mul($numChunks, $sepLen)
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $map['length'])
        );

        $inPosSlot = $context->builder->alloca($i64, 1, 'chunksplit_in_pos');
        $outPosSlot = $context->builder->alloca($i64, 1, 'chunksplit_out_pos');
        $context->builder->store($zero, $inPosSlot);
        $context->builder->store($zero, $outPosSlot);

        $loopHead = BasicBlockHelper::append($context, 'chunksplit_head');
        $loopBody = BasicBlockHelper::append($context, 'chunksplit_body');
        $loopDone = BasicBlockHelper::append($context, 'chunksplit_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $inPos = $context->builder->load($inPosSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $inPos, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $inPos = $context->builder->load($inPosSlot);
        $outPos = $context->builder->load($outPosSlot);
        $remain = $context->builder->sub($inLen, $inPos);
        $thisLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $chunkLen, $remain),
            $remain,
            $chunkLen
        );
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $inPos),
            $thisLen,
            false
        );
        $outAfterChunk = $context->builder->add($outPos, $thisLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outAfterChunk),
            $sepPtr,
            $sepLen,
            false
        );
        $context->builder->store(
            $context->builder->add($outAfterChunk, $sepLen),
            $outPosSlot
        );
        $context->builder->store(
            $context->builder->add($inPos, $chunkLen),
            $inPosSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }
}
