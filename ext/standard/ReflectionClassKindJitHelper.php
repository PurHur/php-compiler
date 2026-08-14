<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Web\Superglobals;

/**
 * ReflectionClass kind/query NestedJIT probes (#31126, ext/reflection/php_reflection.c).
 */
final class ReflectionClassKindJitHelper
{
    public static function isEnum(string $name): bool
    {
        $entry = self::resolveEntry($name);

        return null !== $entry && $entry->isEnum;
    }

    public static function isInterface(string $name): bool
    {
        $entry = self::resolveEntry($name);

        return null !== $entry && ReflectionSupport::reflectionClassIsInterface($entry);
    }

    public static function isTrait(string $name): bool
    {
        $entry = self::resolveEntry($name);

        return null !== $entry && ReflectionSupport::reflectionClassIsTrait($entry);
    }

    public static function isAbstract(string $name): bool
    {
        $entry = self::resolveEntry($name);
        if (null === $entry) {
            return false;
        }

        return $entry->isAbstract || [] !== $entry->abstractMethods;
    }

    public static function isReadOnly(string $name): bool
    {
        $entry = self::resolveEntry($name);

        return null !== $entry && $entry->readonly;
    }

    public static function getModifiers(string $name): int
    {
        $entry = self::resolveEntry($name);
        if (null === $entry) {
            return 0;
        }

        return VmReflection::classEntryToReflectionModifiers($entry);
    }

    private static function resolveEntry(string $name): ?ClassEntry
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
}
