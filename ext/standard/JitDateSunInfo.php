<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for date_sun_info() — compile-time literal baking from VmDate host bridge (#6831). */
final class JitDateSunInfo
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('date_sun_info() expects exactly 3 arguments, %d given', \count($args))
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $time = self::tryCompileTimeLong($context, $args[0]);
        $latitude = self::tryCompileTimeDouble($context, $args[1]);
        $longitude = self::tryCompileTimeDouble($context, $args[2]);
        if (null === $time || null === $latitude || null === $longitude) {
            throw new \LogicException(
                'date_sun_info() requires compile-time numeric arguments in this compiler build (issue #6831)'
            );
        }

        $parsed = VmDate::dateSunInfoNative($time, $latitude, $longitude);
        $ht = JitDateSunInfoMaterializer::materializeParsed($context, $parsed);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function tryCompileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    private static function tryCompileTimeDouble(Context $context, JITVariable $var): ?float
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        if (null !== $var->compileTimeFloat) {
            return $var->compileTimeFloat;
        }

        return null;
    }
}
