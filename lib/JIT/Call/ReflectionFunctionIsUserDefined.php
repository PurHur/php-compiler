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
use PHPLLVM\Value;

/**
 * ReflectionFunction::isUserDefined() — JIT/AOT (#34218, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * php-src: zim_ReflectionFunctionAbstract_isUserDefined ≡ !ZEND_INTERNAL_FUNCTION
 */
final class ReflectionFunctionIsUserDefined implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('isUserDefined'),
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
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isUser);

        return $resultSlot;
    }
}
