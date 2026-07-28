<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * Thin-AOT Closure invoke — compiled per user module, not spine (#24156).
 *
 * Spine-compiled {@see VmClosureCall} cannot reach user-module `{closure}_*` proxies.
 * Helpers call this class so NestedJIT {@see \PHPCompiler\JIT\Call\NestedClosureInvoke}
 * dispatches via `__closure_target` at compile time in the user AOT module.
 */
final class VmClosureInvoke
{
    /**
     * Invoke a Closure Variable — NestedJIT proxy uses {@see \PHPCompiler\JIT\Call\NestedClosureInvoke}
     * for thin-AOT `__closure_target`. VM path uses ClosureState when present.
     */
    public static function invokeVariable(Variable $callback, Variable ...$args): Variable
    {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            $ctx = Superglobals::getActiveContext();
            if (null === $ctx) {
                $ctx = VmActiveContextJitHelper::resolve();
            }

            return VmClosureCall::invoke($ctx, VmClosureCall::resolve($callback), ...$args);
        }

        throw new \LogicException(
            'Callback object is not invokable as a closure in this compiler build (#24156)'
        );
    }

    /** usort-family compare via {@see invokeVariable} (thin AOT NestedJIT, #24156). */
    public static function invokeVariableTwo(Variable $callback, Variable $a, Variable $b): int
    {
        $copyA = new Variable();
        $copyA->duplicateFrom($a);
        $copyB = new Variable();
        $copyB->duplicateFrom($b);
        $result = self::invokeVariable($callback, $copyA, $copyB);

        return VmClosureCall::coerceUserSortCallbackResult($result);
    }
}
