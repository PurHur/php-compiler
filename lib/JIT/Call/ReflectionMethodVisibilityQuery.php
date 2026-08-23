<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionMethodVisibilityRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionMethod::isPublic / isStatic — JIT/AOT (#34216, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * Peer {@see ReflectionClassKindQuery} / {@see ReflectionPropertyIsFinal}.
 */
final class ReflectionMethodVisibilityQuery implements Call
{
    /**
     * @param 'isPublic'|'isStatic' $method
     */
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $display = 'isStatic' === $this->method
            ? 'ReflectionFunctionAbstract::isStatic'
            : 'ReflectionMethod::'.$this->method;
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($display, 0, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_method_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classCstr, $classLen, $methodCstr, $methodLen] =
            ReflectionSetup::reflectionMethodClassAndMethodAsCstr($context, $obj);
        $flag = ReflectionMethodVisibilityRuntime::invoke(
            $context,
            $this->method,
            $classCstr,
            $classLen,
            $methodCstr,
            $methodLen
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $flag);

        return $resultSlot;
    }
}
