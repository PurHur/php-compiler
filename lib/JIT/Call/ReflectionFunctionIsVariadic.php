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

/** ReflectionFunction::isVariadic() — JIT/AOT (#22045, ext/reflection/php_reflection.c). */
final class ReflectionFunctionIsVariadic implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionFunctionAbstract_isVariadic — 0 args; $args[0] is $this (#30924)
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('isVariadic'),
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
        $isVariadic = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_is_variadic'),
            $funcCstr
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isVariadic);

        return $resultSlot;
    }
}
