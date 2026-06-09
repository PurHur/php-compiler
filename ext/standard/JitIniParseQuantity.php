<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IniParseQuantityRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT/AOT lowering for ini_parse_quantity() — mirrors {@see VmIniQuantity}. */
final class JitIniParseQuantity
{
    public static function invoke(Context $context, Value $shorthandStr): Value
    {
        IniParseQuantityRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $strData = $context->builder->structGep(
            $shorthandStr,
            $context->structFieldMap['__string__']['value']
        );

        return $context->builder->call(
            $context->lookupFunction('__compiler_ini_parse_quantity'),
            $strData
        );
    }
}
