<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IncludePathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for get/set/restore_include_path (issues #3223, #6051). */
final class JitIncludePath
{
    public static function get(Context $context): Value
    {
        IncludePathRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_get_include_path'), $ptr);

        return $ptr;
    }

    public static function set(Context $context, Value $newPath): Value
    {
        return self::setValidated($context, $newPath);
    }

    /** php-src zend_alter_ini_entry — empty include_path returns false without mutation (#12165). */
    public static function setValidated(Context $context, Value $newPath): Value
    {
        IncludePathRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__compiler_set_include_path'), $newPath, $ptr);

        return $ptr;
    }

    public static function restore(Context $context): void
    {
        IncludePathRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_restore_include_path'));
    }
}
