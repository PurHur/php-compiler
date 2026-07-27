<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable;

/**
 * Invoke internal builtins from array_find-family callbacks with php-src arity (#17300, #13946).
 */
final class VmArrayFindInternalInvoke
{
    /**
     * @param bool $unaryUsesKey When true and the internal accepts one arg, pass the key operand.
     */
    public static function invoke(
        Internal $fn,
        Variable $value,
        Variable $key,
        bool $unaryUsesKey = false,
        bool $keyFirst = false,
    ): Variable {
        $maxArgs = InternalArityPolicy::maxArgsForArrayCallback($fn);
        if ($maxArgs <= 1) {
            return VmInternalCall::invoke($fn, $unaryUsesKey ? $key : $value);
        }
        if ($keyFirst) {
            return VmInternalCall::invoke($fn, $key, $value);
        }

        return VmInternalCall::invoke($fn, $value, $key);
    }
}
