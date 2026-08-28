<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IniRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** ini_get/ini_set/ini_restore/get_cfg_var JIT — {@see IniRuntime} (#9249). Call-site ensureLinked (#35614). */
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
}
