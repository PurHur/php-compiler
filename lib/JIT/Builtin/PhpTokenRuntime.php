<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * PhpToken JIT/AOT markers (#27263) — name lookup is inlined in
 * {@see \PHPCompiler\JIT\Call\PhpTokenGetTokenName} (no NestedJIT).
 */
final class PhpTokenRuntime
{
    public static function ensureLinked(Context $context): void
    {
        $context->type->object->lookup('PhpToken');
    }
}
