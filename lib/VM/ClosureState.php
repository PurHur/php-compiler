<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Func;

/**
 * VM state for anonymous functions / closures (issue #72).
 *
 * Closures are represented as objects with an attached {@see ClosureState} rather than
 * registering each instance as a named user function.
 */
final class ClosureState
{
    /**
     * Bound `use ($var)` values captured when the closure object was created.
     *
     * @var list<array{slot: int, var: Variable, byRef: bool}>
     */
    public array $captures;

    /**
     * @param list<array{slot: int, var: Variable, byRef: bool}> $captures
     */
    public function __construct(
        public readonly Func\PHP $func,
        array $captures = [],
    ) {
        $this->captures = $captures;
    }

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry('Closure');
        $ctx->classes['closure'] = $entry;
    }

    public function wrapObject(Context $ctx): ObjectEntry
    {
        $entry = new ObjectEntry($ctx->classes['closure']);
        $entry->closureState = $this;
        $entry->constructed = true;

        return $entry;
    }
}
