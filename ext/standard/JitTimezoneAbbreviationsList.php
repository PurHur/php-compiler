<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_abbreviations_list() — compile-time timelib map (#11874).
 *
 * Arity is enforced by timezone_abbreviations_list::call (#30681).
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_abbreviations_list)
 */
final class JitTimezoneAbbreviationsList
{
    public static function invoke(Context $context): Value
    {
        $source = VmDateTimeNative::timezoneAbbreviationsListVariable()->toArray();
        $htVar = HashTableHelper::variableFromVmHashTable($context, $source);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($htVar)
        );

        return $ptr;
    }
}
