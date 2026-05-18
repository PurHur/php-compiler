<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitUrlencode
{
    public static function urlencode(Context $context, Value $strPtr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__urlencode'),
            $strPtr
        );
    }

    public static function rawurlencode(Context $context, Value $strPtr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__string__rawurlencode'),
            $strPtr
        );
    }
}
