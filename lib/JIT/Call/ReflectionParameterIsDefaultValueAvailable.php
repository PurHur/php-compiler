<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

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

/** ReflectionParameter::isDefaultValueAvailable() — JIT/AOT (#28780). */
final class ReflectionParameterIsDefaultValueAvailable implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionParameter::isDefaultValueAvailable()',
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
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME
        );
        $index = ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX
        );
        $available = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_default_available'),
            $funcCstr,
            $index
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $available);

        return $resultSlot;
    }
}
