<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Variable;

/**
 * Elide discarded calls to compile-time-pure builtins (#23483 call-overhead slice).
 *
 * php-src: ZPP may still run user-visible coercions; here we only fold cases that are
 * side-effect-free at compile time (literal strlen operand, no strict_types hazard).
 */
final class DiscardedPureCallElision
{
    /**
     * @param array<int, Variable> $callArgs
     */
    public static function tryElide(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strlen' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return null !== JitStringArg::compileTimeLiteral($callArgs[0]);
    }
}
