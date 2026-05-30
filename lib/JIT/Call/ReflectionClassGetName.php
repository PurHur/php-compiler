<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ReflectionClassGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $objArg = $context->builder->pointerCast($obj, $i8p);
        $outLen = BasicBlockHelper::entryAlloca($context, $sizeT);
        $namePtr = $context->builder->call($context->lookupFunction('phpc_reflect_get_class_name'), $objArg, $outLen);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $namePtr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $cstr = $context->builder->select($isNull, $empty, $namePtr);
        $len = $context->builder->load($outLen);
        $len64 = $context->builder->zExt($len, $i64);
        $len64 = $context->builder->select($isNull, $i64->constInt(0, false), $len64);

        return $context->builder->call($context->lookupFunction('__string__init'), $len64, $cstr);
    }
}
