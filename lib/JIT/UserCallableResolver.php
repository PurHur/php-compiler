<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Compile-time resolution of string callables that name a user function
 * (`array_map('fmt', $xs)`, `usort($a, 'cmp')`).
 *
 * php-src resolves these through the function table at call time (zend_is_callable_ex);
 * for a compile-time literal naming a function declared in this program the target is
 * fixed, so callers can invoke its Native call directly. Builtin names are left to the
 * existing builtin paths (a user function cannot shadow one — redeclaration is fatal).
 */
final class UserCallableResolver
{
    public static function userFunctionCall(Context $context, Variable $callback): ?Call
    {
        $name = $callback->compileTimeString;
        if (!\is_string($name) || '' === $name || str_contains($name, '::')) {
            return null;
        }
        $lc = strtolower(ltrim($name, '\\'));
        if (null !== \PHPCompiler\ext\standard\BuiltinRegistry::resolve($lc)) {
            return null;
        }
        if (!$context->functionIsRegistered($lc)) {
            return null;
        }
        $call = $context->resolveFunctionProxy($lc);

        return $call instanceof Call\Native ? $call : null;
    }
}
