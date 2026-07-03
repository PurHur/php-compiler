<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\VM\Variable;

/** VM helpers for secretstream by-ref state (#15462). */
final class VmSodiumSecretstream
{
    public static function readState(Variable $var, string $fn): string
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $target->type) {
            throw new \SodiumException(\sprintf(
                '%s(): Argument #1 ($state) must be a reference to a state',
                $fn
            ));
        }

        return $target->toString();
    }

    public static function writeState(Variable $var, string $state): void
    {
        $target = $var->resolveIndirect();
        $target->string($state);
    }
}
