<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Context::runStackFrames() for nested php-in-PHP JIT helpers (#13245).
 *
 * Returns an empty array so JsonEncodeJitHelper can use $frames[0] ?? null without
 * compiling lib/VM/Context.php stack walk in NestedJitCompileScope.
 */
final class ContextRunStackFramesNested implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return HashTableHelper::alloc($context);
    }
}
