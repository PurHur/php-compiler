<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionParameter::getName() — JIT/AOT (#33993, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionParameter::getName',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_param_getname_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_NAME
        );
        $len64 = $context->builder->zExt($len, $i64);

        return $context->builder->call($context->lookupFunction('__string__init'), $len64, $cstr);
    }
}
