<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCfg\Func;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\Builtin\ReflectionMethodQueryLookupHelper;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionMethod::isStatic() — JIT/AOT (#34216, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsStatic implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            VmReflection::functionAbstractReceiverOnlyDisplayName('isStatic'),
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $flags = ReflectionMethodQueryLookupHelper::lookupMethodFlags($context, $args[0]);
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $found = $context->builder->icmp(Builder::INT_NE, $flags, $zero);
        $isStaticRaw = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i32->constInt(Func::FLAG_STATIC, false)),
            $zero
        );
        $isStatic = $context->builder->and($found, $isStaticRaw);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isStatic);

        return $resultSlot;
    }
}
