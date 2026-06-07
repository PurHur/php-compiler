<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_resource_type() via __compiler_get_resource_type (#3142, #5845). */
final class JitGetResourceType
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $arg, 'get_resource_type');
        if (JITVariable::TYPE_NULL === $arg->type) {
            JitResourceArg::emitResourceTypeErrorAndAbort($context, 'get_resource_type', 0, 'resource', 'null');

            return JitValueBox::alloc($context);
        }

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'get_resource_type() argument #1 ($resource)'),
            $context->getTypeFromString('int64')
        );
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

        return $ptr;
    }
}
