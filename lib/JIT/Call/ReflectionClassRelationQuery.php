<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassRelationQueryRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::implementsInterface / isSubclassOf — JIT/AOT (#34080).
 *
 * Thin AOT previously had no proxies; ExternalMethod returned NULL → false.
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_implementsInterface
 * / zim_ReflectionClass_isSubclassOf
 */
final class ReflectionClassRelationQuery implements Call
{
    /**
     * @param 'implementsInterface'|'isSubclassOf' $method
     */
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $display = 'ReflectionClass::'.$this->method;
        $userArgCount = \count($args) - 1;
        // php-src: exactly 1 arg (name or ReflectionClass); $args[0] is $this
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($display, 1, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classCstr, $classLen] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $targetVar = JitNativeString::coerce($context, $args[1]);
        $targetStr = $context->helper->loadValue($targetVar);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $context->builder->pointerCast($targetStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $targetData = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $targetLen = $context->builder->zExt($len, $sizeT);

        $flag = ReflectionClassRelationQueryRuntime::invoke(
            $context,
            $this->method,
            $classCstr,
            $classLen,
            $context->builder->pointerCast($targetData, $i8p),
            $targetLen
        );
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $flag);

        return $resultSlot;
    }
}
