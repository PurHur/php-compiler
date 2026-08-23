<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassKindNameTableRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass kind queries under thin AOT (#34032, ext/reflection/php_reflection.c).
 *
 * Covers isInterface / isAbstract / isTrait / isEnum — previously unbound → NULL.
 */
final class ReflectionClassKindQuery implements Call
{
    /** @param 'isInterface'|'isAbstract'|'isTrait'|'isEnum' $method */
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $display = 'ReflectionClass::'.$this->method;
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($display, 0, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $flag = ReflectionClassKindNameTableRuntime::invoke(
            $context,
            strtolower($this->method),
            $cstr,
            $len
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $flag);

        return $resultSlot;
    }
}
