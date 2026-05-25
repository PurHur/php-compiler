<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

final class JitIni
{
    public static function set(Context $context, Value $optionStr, Value $valueStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_ini_set'), $optionStr, $valueStr, $ptr);

        return $ptr;
    }

    public static function get(Context $context, Value $optionStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_ini_get'), $optionStr, $ptr);

        return $ptr;
    }
}
