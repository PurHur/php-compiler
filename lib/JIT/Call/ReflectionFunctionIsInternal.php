<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\Builtin\ReflectionNative;
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

/**
 * ReflectionFunction::isInternal() — JIT/AOT (#34218, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * php-src: zim_ReflectionFunctionAbstract_isInternal ≡ !isUserDefined
 */
final class ReflectionFunctionIsInternal implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('isInternal'),
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $isUser = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_is_user_defined'),
            $funcCstr
        );
        $i1 = $context->getTypeFromString('int1');
        $isInternal = $context->builder->icmp(
            Builder::INT_EQ,
            $isUser,
            $i1->constInt(0, false)
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isInternal);

        return $resultSlot;
    }
}
