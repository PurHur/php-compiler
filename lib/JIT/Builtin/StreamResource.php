<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamResourceKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for get_resource_type()/get_resources() (#5179, #3646, #6821, #19613, #33130, #33183).
 *
 * Owns `__compiler_get_resource_type` / `__compiler_get_resources` ABI module-locally via
 * {@see JitStreamResourceKernel} (getNamedFunction first). Do not re-add empty always-on shells
 * in {@see Type} — leftover decls mint get_resource_type.1 / get_resources.1 (#31894 / #32122).
 */
final class StreamResource
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamResourceKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
