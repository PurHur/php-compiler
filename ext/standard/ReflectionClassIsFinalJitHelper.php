<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/** ReflectionClass::isFinal() AOT/VM probe helper (#18297). Kept for NestedJIT callers. */
final class ReflectionClassIsFinalJitHelper
{
    public static function probe(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ReflectionClassIsFinalJitHelper::probe() requires an active VM context'
            );
        }
        $lc = strtolower(ltrim($name, '\\'));
        $entry = $ctx->classes[$lc] ?? null;

        return null !== $entry && ($entry->isFinal || $entry->isEnum);
    }
}
