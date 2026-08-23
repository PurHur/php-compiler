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
use PHPLLVM\Value;

/**
 * ReflectionMethod::getNumberOfParameters() — JIT/AOT (#34216, ext/reflection/php_reflection.c).
 */
final class ReflectionMethodGetNumberOfParameters implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
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
        [$classCstr, , $methodCstr] = ReflectionSetup::reflectionMethodClassAndMethodAsCstr($context, $obj);
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_refl_method_param_count'),
            $classCstr,
            $methodCstr
        );
        $i64 = $context->getTypeFromString('int64');
        $countI64 = $context->builder->zExt($count, $i64);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $resultSlot, $countI64);

        return $resultSlot;
    }
}
