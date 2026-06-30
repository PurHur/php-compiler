<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_abbreviations_list() — compile-time timelib map (#11874).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_abbreviations_list)
 */
final class JitTimezoneAbbreviationsList
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('timezone_abbreviations_list() expects exactly 0 arguments, %d given', \count($args))
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

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
