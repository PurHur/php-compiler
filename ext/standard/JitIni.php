<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IniRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

final class JitIni
{
    public static function set(Context $context, Value $optionStr, Value $valueStr): Value
    {
        IniRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_ini_set'), $optionStr, $valueStr, $ptr);

        return $ptr;
    }

    public static function get(Context $context, Value $optionStr): Value
    {
        IniRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_ini_get'), $optionStr, $ptr);

        return $ptr;
    }

    /**
     * Literal-option thin path — avoids runtime key strcasecmp that can match the wrong
     * branch when BSS/__string__ compares are unreliable under NestedJIT AOT (#33059).
     */
    public static function getLiteral(Context $context, string $optionKey): Value
    {
        IniRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        IniRuntime::emitThinGetKnownKey($context, $ptr, strtolower($optionKey));

        return $ptr;
    }

    /**
     * @return Value|null null when the key has no thin set path (caller uses NestedJIT)
     */
    public static function setLiteral(Context $context, string $optionKey, string $newValue): ?Value
    {
        $key = strtolower($optionKey);
        if ('precision' !== $key && 'serialize_precision' !== $key) {
            return null;
        }
        IniRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        IniRuntime::emitThinSetI32Ini($context, $ptr, $key, $newValue);

        return $ptr;
    }

    public static function getCfgVar(Context $context, Value $optionStr): Value
    {
        IniRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_ini_cfg_get'), $optionStr, $ptr);

        return $ptr;
    }

    public static function restore(Context $context, Value $optionStr): void
    {
        IniRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_ini_restore'), $optionStr);
    }

    public static function restoreLiteral(Context $context, string $optionKey): void
    {
        IniRuntime::ensureLinked($context);
        IniRuntime::emitThinRestoreKnownKey($context, strtolower($optionKey));
    }
}
