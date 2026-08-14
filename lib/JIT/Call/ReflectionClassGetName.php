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
use PHPLLVM\Value;

/** ReflectionClass::getName() — JIT/AOT (#9878, #30888). */
final class ReflectionClassGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getName — 0 args; $args[0] is $this (#30888)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getName',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_class_getname_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $len64 = $context->builder->zExt($len, $i64);

        return $context->builder->call($context->lookupFunction('__string__init'), $len64, $cstr);
    }
}
