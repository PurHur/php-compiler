<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionConstant::getNamespaceName() — JIT/AOT (#21551, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetNamespaceName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr($context, $obj, 'ReflectionConstant', 'constant');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $backslash = $i32->constInt(ord('\\'), false);
        $slashPtr = $context->builder->call($context->lookupFunction('strrchr'), $cstr, $backslash);
        $nullPtr = $i8p->constNull();
        $hasSlash = $context->builder->icmp(Builder::INT_NE, $slashPtr, $nullPtr);
        $slashOffset = $context->builder->ptrToInt($slashPtr, $i64);
        $baseOffset = $context->builder->ptrToInt($cstr, $i64);
        $nsLen64 = $context->builder->sub($slashOffset, $baseOffset);
        $nsLen = $context->builder->select(
            $hasSlash,
            $context->builder->zExt($nsLen64, $sizeT),
            $sizeT->constInt(0, false)
        );
        $nsCstr = $context->builder->select(
            $hasSlash,
            $cstr,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($nsLen, $i64),
            $nsCstr
        );
    }
}
