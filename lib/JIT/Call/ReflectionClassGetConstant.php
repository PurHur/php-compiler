<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassGetConstantRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::getConstant() — JIT/AOT (#34093, #6950, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * Literal constant names dispatch via {@see ReflectionClassGetConstantRuntime}
 * (peer {@see ClassConstFetchHelper::fetchLiteralConstWithRuntimeClass}; miss → false).
 *
 * php-src: zim_ReflectionClass_getConstant
 */
final class ReflectionClassGetConstant implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getConstant — exactly 1 user arg (#30888)
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getConstant',
                    1,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getconstant_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $constLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $constLit) {
            throw new \LogicException(
                'ReflectionClass::getConstant() name must be a string literal in this compiler build'
            );
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $classIdVal = $context->type->object->classIdFromRuntimeName($cstr, $len);

        return ReflectionClassGetConstantRuntime::emitForLiteralName(
            $context,
            $classIdVal,
            $constLit
        );
    }
}
