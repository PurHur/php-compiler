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

/** ReflectionParameter::hasType() — JIT/AOT (#28780). */
final class ReflectionParameterHasType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionParameter::hasType()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        LibcExtern::ensureStrlenDecl($context);
        $labelPtr = ReflectionParameterGetType::paramTypeLabelCstr($context, $args[0]);
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
