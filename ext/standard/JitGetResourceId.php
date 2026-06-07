<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_id() via __compiler_is_resource (#3180, #5845). */
final class JitGetResourceId
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $arg, 'get_resource_id');
        if (JITVariable::TYPE_NULL === $arg->type) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'get_resource_id', 0, 'resource', 'null');

            return $context->constantFromInteger(0, 'int64');
        }

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'get_resource_id() argument #1 ($resource)'),
            $context->getTypeFromString('int64')
        );
        $isRes = JitIsResource::invoke($context, $handle);
        $okBlock = BasicBlockHelper::append($context, 'get_resource_id_ok');
        $errBlock = BasicBlockHelper::append($context, 'get_resource_id_err');
        $context->builder->branchIf($isRes, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        JitResourceArg::emitResourceTypeErrorAndAbort(
            $context,
            'get_resource_id',
            0,
            'resource',
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($okBlock);

        return $handle;
    }
}
