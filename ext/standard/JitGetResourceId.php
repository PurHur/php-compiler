<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_id() via __compiler_is_resource (#3180, #5845, #8743). */
final class JitGetResourceId
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $arg, 'get_resource_id');
        if (JITVariable::TYPE_NULL === $arg->type) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'get_resource_id', 0, 'resource', 'null');

            return $context->constantFromInteger(0, 'int64');
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return self::invokeMaybeStreamContext($context, $arg);
        }

        return self::invokeHandle($context, $arg);
    }

    private static function invokeMaybeStreamContext(Context $context, JITVariable $arg): Value
    {
        $isCtx = JitStreamContextRepresentation::isRepresentationArg($context, $arg);
        $ctxBlock = BasicBlockHelper::append($context, 'get_resource_id_stream_ctx');
        $handleBlock = BasicBlockHelper::append($context, 'get_resource_id_handle_path');
        $doneBlock = BasicBlockHelper::append($context, 'get_resource_id_done');
        $context->builder->branchIf($isCtx, $ctxBlock, $handleBlock);

        $context->builder->positionAtEnd($ctxBlock);
        $ht = JitStreamContextRepresentation::hashtableFromArg($context, $arg);
        $ctxId = JitStreamContextRepresentation::readMarkerId($context, $ht);
        $ctxEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($handleBlock);
        $handleId = self::invokeHandle($context, $arg);
        $handleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64, 'get_resource_id_phi');
        $phi->addIncoming($ctxId, $ctxEnd);
        $phi->addIncoming($handleId, $handleEnd);

        return $phi;
    }

    private static function invokeHandle(Context $context, JITVariable $arg): Value
    {
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'get_resource_id() argument #1 ($resource)'),
            $context->getTypeFromString('int64')
        );
        $isRes = JitIsResource::invoke($context, $handle);
        $okBlock = BasicBlockHelper::append($context, 'get_resource_id_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_resource_id_err');
        $context->builder->branchIf($isRes, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        JitResourceArg::emitResourceTypeErrorForOperandAndAbort(
            $context,
            'get_resource_id',
            0,
            'resource',
            $arg
        );

        $context->builder->positionAtEnd($okBlock);

        return $handle;
    }
}
