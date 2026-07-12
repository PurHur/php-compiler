<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\Web\Superglobals;

/** ReflectionClass::isIterateable() AOT bridge (#18297). */
final class ReflectionClassIsIterateableJitHelper
{
    public static function probe(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ReflectionClassIsIterateableJitHelper::probe() requires an active VM context'
            );
        }
        $lc = strtolower(ltrim($name, '\\'));
        $entry = $ctx->classes[$lc] ?? null;
        if (null === $entry) {
            return false;
        }

        return InterfaceCheck::entryImplements($entry, 'traversable', $ctx);
    }
}
