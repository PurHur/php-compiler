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
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionParameter::isVariadic() — JIT/AOT (#24461, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsVariadic implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionParameter::isVariadic()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

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

        $methodBlock = BasicBlockHelper::append($context, 'refl_param_variadic_method');
        $funcBlock = BasicBlockHelper::append($context, 'refl_param_variadic_func');
        $merge = BasicBlockHelper::append($context, 'refl_param_variadic_merge');
        $resultSlot = JitValueBox::alloc($context);
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
        $isVariadic = $context->builder->call(
            $context->lookupFunction('__compiler_param_method_is_variadic'),
            $classCstr,
            $methodCstr,
            $position
        );
        JitValueBox::writeBool($context, $resultSlot, $isVariadic);
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
        $isVariadic = $context->builder->call(
            $context->lookupFunction('__compiler_param_func_is_variadic'),
            $funcCstr,
            $index
        );
        JitValueBox::writeBool($context, $resultSlot, $isVariadic);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }
}
