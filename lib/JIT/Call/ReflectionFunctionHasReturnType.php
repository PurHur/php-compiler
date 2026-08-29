<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionFunction::hasReturnType() — JIT/AOT (#28780). */
final class ReflectionFunctionHasReturnType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionFunction::hasReturnType()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        LibcExtern::ensureStrlenDecl($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $labelPtr = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_return_type_label'),
            $funcCstr
        );
        $labelLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $labelPtr
        );
        $resultSlot = JitValueBox::alloc($context);
        $has = $context->builder->icmp(
            Builder::INT_UGT,
            $labelLen,
            $context->getTypeFromString('size_t')->constInt(0, false)
        );
        JitValueBox::writeBool($context, $resultSlot, $has);

        return $resultSlot;
    }
}
