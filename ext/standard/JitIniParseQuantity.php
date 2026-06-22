<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IniParseQuantityRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT lowering for ini_parse_quantity() — routes through IniParseQuantityJitHelper PHP (#9237). */
final class JitIniParseQuantity
{
    public static function invoke(Context $context, Value $shorthandStr): Value
    {
        IniParseQuantityRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_ini_parse_quantity'),
            $shorthandStr
        );
    }
}
