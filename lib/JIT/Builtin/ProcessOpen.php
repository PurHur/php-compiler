<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for proc_open()/proc_close()/proc_get_status()/proc_terminate()
 * (php-src ext/standard/proc_open.c; #6904, #9408, #33105, #33118, #33121).
 *
 * Owns `__compiler_proc_close` / `__compiler_is_process_resource` (and peer
 * proc_open) ABI module-locally via {@see ProcessOpenEmbedBridge}
 * (getNamedFunction first). Do not re-add empty always-on shells in {@see Type}
 * — leftover decls mint is_process_resource.1 (#31894 / #32122).
 */
final class ProcessOpen
{
    public static function ensureLinked(Context $context): void
    {
        ProcessOpenRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        ProcessOpenRuntime::implement($context);
    }
}
