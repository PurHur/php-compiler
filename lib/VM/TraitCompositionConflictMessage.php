<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-compatible trait composition conflict diagnostics (#7418, zend_traits.c).
 */
final class TraitCompositionConflictMessage
{
    public static function incompatibleProperty(
        string $firstTrait,
        string $secondTrait,
        string $propertyName,
        string $className,
    ): string {
        return sprintf(
            '%s and %s define the same property ($%s) in the composition of %s. '
            .'However, the definition differs and is considered incompatible. Class was composed',
            $firstTrait,
            $secondTrait,
            $propertyName,
            $className,
        );
    }
}
