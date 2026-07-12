<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\Web\Superglobals;

/** ReflectionClass kind/modifier probes for JIT/AOT (#18335). */
final class ReflectionClassKindJitHelper
{
    private static function entry(string $name): ?ClassEntry
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ReflectionClassKindJitHelper requires an active VM context'
            );
        }
        $lc = strtolower(ltrim($name, '\\'));

        return $ctx->classes[$lc] ?? null;
    }

    public static function probeIsInterface(string $name): bool
    {
        $entry = self::entry($name);

        return null !== $entry && $entry->isInterface;
    }

    public static function probeIsTrait(string $name): bool
    {
        $entry = self::entry($name);

        return null !== $entry && $entry->isTrait;
    }

    public static function probeGetModifiers(string $name): int
    {
        $entry = self::entry($name);
        if (null === $entry) {
            return 0;
        }

        return VmReflection::classEntryToReflectionModifiers($entry);
    }
}
