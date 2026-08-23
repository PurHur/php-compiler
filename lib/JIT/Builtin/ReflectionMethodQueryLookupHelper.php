<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Read ReflectionMethod query metadata stamped at construct (#34216). */
final class ReflectionMethodQueryLookupHelper
{
    public static function lookupMethodFlags(Context $context, Variable $thisArg): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $thisArg);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->trunc(
            ReflectionMethodQueryConstructHelper::loadFlags($context, $obj),
            $i32
        );
    }

    public static function lookupParameterCount(Context $context, Variable $thisArg): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $thisArg);

        return ReflectionMethodQueryConstructHelper::loadParamCount($context, $obj);
    }

    public static function lookupRequiredParameterCount(Context $context, Variable $thisArg): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $thisArg);

        return ReflectionMethodQueryConstructHelper::loadRequiredParamCount($context, $obj);
    }
}
