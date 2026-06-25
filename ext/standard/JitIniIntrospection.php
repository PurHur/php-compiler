<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\IniIntrospectionRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** JIT lowering for php_ini_loaded_file() / php_ini_scanned_files() via PHP helper (#6117, #11562). */
final class JitIniIntrospection
{
    public static function loadedFile(Context $context): Value
    {
        IniIntrospectionRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ini_introspection_loaded_file'),
            $ptr
        );

        return $ptr;
    }

    public static function scannedFiles(Context $context): Value
    {
        IniIntrospectionRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ini_introspection_scanned_files'),
            $ptr
        );

        return $ptr;
    }
}
