<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Sealed class / interface metadata from AST preprocessor (#3322).
 *
 * php-src: zend_inheritance.c — zend_check_sealed.
 */
final class ClassSealed
{
    /**
     * @param list<string> $sealedPermits lowercase permitted child names; empty = none allowed
     */
    public static function childMayInherit(string $childLc, array $sealedPermits): bool
    {
        if ([] === $sealedPermits) {
            return false;
        }

        return in_array($childLc, $sealedPermits, true);
    }

    public static function cannotExtendMessage(string $child, string $parent): string
    {
        return sprintf('Class %s cannot extend sealed class %s', $child, $parent);
    }

    public static function cannotImplementMessage(string $child, string $iface): string
    {
        return sprintf('Class %s cannot implement sealed interface %s', $child, $iface);
    }

    public static function notInPermitsListMessage(string $child, string $parent): string
    {
        return sprintf('Class "%s" is not in the list of allowed subclasses for sealed class "%s"', $child, $parent);
    }
}
