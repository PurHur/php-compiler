<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionParameter::allowsNull() — JIT/AOT (#28780). */
final class ReflectionParameterAllowsNull implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionParameter::allowsNull()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        LibcExtern::ensureStrncmp($context);
        $labelPtr = ReflectionParameterGetType::paramTypeLabelCstr($context, $args[0]);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $qmark = $context->builder->pointerCast($context->constantFromString('?'), $i8p);
        $startsNullable = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $labelPtr,
            $qmark,
            $context->getTypeFromString('size_t')->constInt(1, false)
        );
        $isNullable = $context->builder->icmp(
            Builder::INT_EQ,
            $startsNullable,
            $i32->constInt(0, false)
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isNullable);

        return $resultSlot;
    }
}
