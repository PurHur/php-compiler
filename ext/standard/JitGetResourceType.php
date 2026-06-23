<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringDir;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_type() via __compiler_get_resource_type (#3142, #5845, #8743). */
final class JitGetResourceType
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $arg, 'get_resource_type');
        if (JITVariable::TYPE_NULL === $arg->type) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'get_resource_type', 0, 'resource', 'null');

            return JitValueBox::alloc($context);
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return self::invokeMaybeStreamContext($context, $arg);
        }

        return self::invokeHandle($context, $arg);
    }

    private static function invokeMaybeStreamContext(Context $context, JITVariable $arg): Value
    {
        $isCtx = JitStreamContextRepresentation::isRepresentationArg($context, $arg);
        $ctxBlock = BasicBlockHelper::append($context, 'get_resource_type_stream_ctx');
        $handleBlock = BasicBlockHelper::append($context, 'get_resource_type_handle_path');
        $doneBlock = BasicBlockHelper::append($context, 'get_resource_type_done');
        $context->builder->branchIf($isCtx, $ctxBlock, $handleBlock);

        $context->builder->positionAtEnd($ctxBlock);
        $ctxSlot = JitValueBox::alloc($context);
        $ctxPtr = JitValueBox::pointer($context, $ctxSlot);
        $ctxStr = $context->builder->call(
            $context->lookupFunction('__string__literal'),
            $context->constantStringFromString('stream-context')
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ctxPtr, $ctxStr);
        $ctxEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($handleBlock);
        $handlePtr = self::invokeHandle($context, $arg);
        $handleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy, 'get_resource_type_phi');
        $phi->addIncoming($ctxPtr, $ctxEnd);
        $phi->addIncoming($handlePtr, $handleEnd);

        return $phi;
    }

    private static function invokeHandle(Context $context, JITVariable $arg): Value
    {
        StringDir::ensureLinked($context);

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'get_resource_type() argument #1 ($resource)'),
            $context->getTypeFromString('int64')
        );
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $isDir = $context->builder->call(
            $context->lookupFunction('__compiler_is_dir_resource'),
            $handle
        );
        $isDirOk = $context->builder->icmp(Builder::INT_NE, $isDir, $zeroI32);
        $dirOkBlock = BasicBlockHelper::append($context, 'get_resource_type_dir_ok');
        $probeBlock = BasicBlockHelper::append($context, 'get_resource_type_probe');
        $context->builder->branchIf($isDirOk, $dirOkBlock, $probeBlock);

        $context->builder->positionAtEnd($dirOkBlock);
        $dirSlot = JitValueBox::alloc($context);
        $dirPtr = JitValueBox::pointer($context, $dirSlot);
        $streamStr = $context->builder->call(
            $context->lookupFunction('__string__literal'),
            $context->constantStringFromString('stream')
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $dirPtr, $streamStr);
        $dirEnd = $context->builder->getInsertBlock();
        $doneBlock = BasicBlockHelper::append($context, 'get_resource_type_handle_done');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($probeBlock);
        $isRes = JitIsResource::invoke($context, $handle);
        $okBlock = BasicBlockHelper::append($context, 'get_resource_type_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_resource_type_err');
        $context->builder->branchIf($isRes, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        JitResourceArg::emitResourceTypeErrorAndAbort(
            $context,
            'get_resource_type',
            0,
            'resource',
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($okBlock);
        $typeStr = $context->builder->call(
            $context->lookupFunction('__compiler_get_resource_type'),
            $handle
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $typeStr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $streamEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy, 'get_resource_type_handle_phi');
        $phi->addIncoming($dirPtr, $dirEnd);
        $phi->addIncoming($ptr, $streamEnd);

        return $phi;
    }
}
