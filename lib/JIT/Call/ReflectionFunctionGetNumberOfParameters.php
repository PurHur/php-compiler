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
 * ReflectionFunction::getNumberOfParameters() — JIT/AOT (#34218, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * Peer {@see ReflectionFunctionIsVariadic} (#22045).
 */
final class ReflectionFunctionGetNumberOfParameters implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionFunctionAbstract_getNumberOfParameters — 0 args (#30924)
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('getNumberOfParameters'),
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
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_count'),
            $funcCstr
        );
        $i64 = $context->getTypeFromString('int64');
        $countI64 = $context->builder->zExt($count, $i64);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $resultSlot, $countI64);

        return $resultSlot;
    }
}
