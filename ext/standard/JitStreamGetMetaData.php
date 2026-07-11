<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamMeta;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_get_meta_data() via __compiler_stream_get_meta_data (issue #6007). */
final class JitStreamGetMetaData
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamMeta::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'stream_get_meta_data_cont');
        }

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();
        $metaHt = $context->builder->call(
            $context->lookupFunction('__compiler_stream_get_meta_data'),
            $handleLong
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $metaHt, $nullHt);
        $failBlock = BasicBlockHelper::append($context, 'stream_get_meta_data_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_get_meta_data_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_get_meta_data_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $arraySlot = JitValueBox::alloc($context);
        $arrayPtr = JitValueBox::pointer($context, $arraySlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $arrayPtr,
            $metaHt
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($arrayPtr, $okTail);

        return $result;
    }
}
