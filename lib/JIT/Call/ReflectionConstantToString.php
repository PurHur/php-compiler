<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionConstant::__toString() — JIT/AOT (#21551).
 *
 * Emits `Constant [ (<persistent> )?mixed NAME ] { }\n`. Full type/value rendering stays on the
 * VM path (php-src _const_string); AOT keeps the method callable with name + persistent flag.
 */
final class ReflectionConstantToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        DefineRuntime::ensureLinked($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$nameCstr, $nameLen] = ReflectionSetup::stringPropertyAsCstr($context, $obj, 'ReflectionConstant', 'constant');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($nameLen, $i64),
            $nameCstr
        );
        $ht = DefineRuntime::loadTable($context);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $nameStr
        );
        $valTy = $context->getTypeFromString('__value__*');
        $isUser = $context->builder->icmp(Builder::INT_NE, $valPtr, $valTy->constNull());

        $prefixUser = $context->builder->pointerCast(
            $context->constantFromString('Constant [ mixed '),
            $i8p
        );
        $prefixPers = $context->builder->pointerCast(
            $context->constantFromString('Constant [ <persistent> mixed '),
            $i8p
        );
        $prefix = $context->builder->select($isUser, $prefixUser, $prefixPers);
        $prefixLen = $context->builder->call($context->lookupFunction('strlen'), $prefix);
        $suffix = $context->builder->pointerCast($context->constantFromString(" ] { }\n"), $i8p);
        $suffixLen = $context->builder->call($context->lookupFunction('strlen'), $suffix);

        $total = $context->builder->add(
            $context->builder->add(
                $context->builder->zExt($prefixLen, $sizeT),
                $context->builder->zExt($nameLen, $sizeT)
            ),
            $context->builder->zExt($suffixLen, $sizeT)
        );
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $total);
        $map = $context->structFieldMap['__string__'];
        $dest = $context->builder->structGep($result, $map['value']);
        $context->intrinsic->builder = $context->builder;
        $context->intrinsic->memcpy($dest, $prefix, $prefixLen, false);
        $afterPrefix = $context->builder->gep($dest, $context->builder->zExt($prefixLen, $sizeT));
        $context->intrinsic->memcpy($afterPrefix, $nameCstr, $nameLen, false);
        $afterName = $context->builder->gep(
            $afterPrefix,
            $context->builder->zExt($nameLen, $sizeT)
        );
        $context->intrinsic->memcpy($afterName, $suffix, $suffixLen, false);

        return $result;
    }
}
