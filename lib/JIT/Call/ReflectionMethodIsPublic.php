<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCfg\Func;
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

/** ReflectionMethod::isPublic() — JIT/AOT (#34216, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsPublic implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionMethod::isPublic',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $flags = ReflectionMethodQueryLookupHelper::lookupMethodFlags($context, $args[0]);
        $isPublic = self::flagsArePublic($context, $flags);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isPublic);

        return $resultSlot;
    }

    private static function flagsArePublic(Context $context, Value $flags): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $found = $context->builder->icmp(Builder::INT_NE, $flags, $zero);
        $notPrivate = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($flags, $i32->constInt(Func::FLAG_PRIVATE, false)),
            $zero
        );
        $notProtected = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($flags, $i32->constInt(Func::FLAG_PROTECTED, false)),
            $zero
        );
        $publicWhenFound = $context->builder->and($notPrivate, $notProtected);

        return $context->builder->and($found, $publicWhenFound);
    }
}
