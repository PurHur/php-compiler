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

    /** Class vs trait property conflict — Zend always names the class first (#11834). */
    public static function incompatibleClassTraitProperty(
        string $className,
        string $traitName,
        string $propertyName,
    ): string {
        return self::incompatibleProperty($className, $traitName, $propertyName, $className);
    }

    /**
     * Runtime E_ERROR fatal for trait property composition (#17995, zend_inheritance.c).
     *
     * @return never
     */
    public static function throwRuntimeFatal(string $message, string $sourceFile, int $sourceLine): void
    {
        $file = '' !== $sourceFile ? $sourceFile : 'Standard input code';
        throw new \LogicException(sprintf(
            'PHP Fatal error:  %s in %s on line %d',
            $message,
            $file,
            max(1, $sourceLine),
        ));
    }
}
