<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionParameter::isSensitiveParameter() — JIT/AOT (#16130, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsSensitiveParameter implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classCstr, $classLen] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_CLASS
        );
        $sizeT = $context->getTypeFromString('size_t');
        $hasClass = $context->builder->icmp(
            Builder::INT_UGT,
            $classLen,
            $sizeT->constInt(0, false)
        );

        $methodBlock = BasicBlockHelper::append($context, 'refl_param_sensitive_method');
        $funcBlock = BasicBlockHelper::append($context, 'refl_param_sensitive_func');
        $merge = BasicBlockHelper::append($context, 'refl_param_sensitive_merge');
        $resultSlot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $context->builder->branchIf($hasClass, $methodBlock, $funcBlock);

        $context->builder->positionAtEnd($methodBlock);
        [$methodCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_METHOD_NAME
        );
        $position = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_POSITION
        );
        $sensitive = $context->builder->call(
            $context->lookupFunction('__compiler_param_method_is_sensitive'),
            $classCstr,
            $methodCstr,
            $position
        );
        JitValueBox::writeBool($context, $resultSlot, $sensitive);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($funcBlock);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME
        );
        $index = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX
        );
        $sensitive = $context->builder->call(
            $context->lookupFunction('__compiler_param_func_is_sensitive'),
            $funcCstr,
            $index
        );
        JitValueBox::writeBool($context, $resultSlot, $sensitive);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }
}
