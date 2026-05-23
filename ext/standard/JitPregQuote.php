<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT helper for preg_quote() — delegates to __string__preg_quote. */
final class JitPregQuote
{
    public static function quote(Context $context, Value $subject, ?Value $delimiter): Value
    {
        if (null === $delimiter) {
            $null = $context->getTypeFromString('__string__*')->constNull();

            return $context->builder->call(
                $context->lookupFunction('__string__preg_quote'),
                $subject,
                $null
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__preg_quote'),
            $subject,
            $delimiter
        );
    }
}
