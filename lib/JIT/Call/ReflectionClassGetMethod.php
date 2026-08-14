<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** ReflectionClass::getMethod() — JIT/AOT (#1936, #30888). */
final class ReflectionClassGetMethod implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getMethod — exactly 1 arg; $args[0] is $this (#30888)
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getMethod',
                    1,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_class_getmethod_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classSafe, $classLen] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $methodVar = JitNativeString::coerce($context, $args[1]);
        $methodStr = $context->helper->loadValue($methodVar);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $context->builder->pointerCast($methodStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $methodData = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $methodLen = $context->builder->zExt($len, $sizeT);

        $rmClassId = $context->type->object->lookup('ReflectionMethod');
        $rmObj = $context->type->object->allocate($rmClassId);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'class',
            $classSafe,
            $classLen
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'name',
            $context->builder->pointerCast($methodData, $i8p),
            $methodLen
        );
        ReflectionSetup::markConstructed($context, $rmObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $rmObj
        );

        return JitValueBox::pointer($context, $slot);
    }
}
