<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;

/**
 * User-script standalone AOT must not nested-JIT php-in-PHP helpers (#15407, #16734).
 *
 * Nested VmString helpers segfault after minimal standalone init; use LLVM/libc paths instead.
 */
final class UserScriptAotDeferNestedJit
{
    public static function shouldDefer(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return true;
        }

        return false;
    }
}
