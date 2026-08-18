<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-compatible trait composition conflict diagnostics (#7418, zend_traits.c).
 */
final class TraitCompositionConflictMessage
{
    /**
     * Missing trait in {@code use} — Zend/zend_compile.c {@code Trait "%s" not found} (#30012).
     */
    public static function notFound(string $traitName): string
    {
        return sprintf('Trait "%s" not found', $traitName);
    }

    /**
     * Non-trait name in {@code insteadof}/{@code as} — zend_inheritance.c zend_check_trait_usage (#32129).
     */
    public static function notATraitForAdaptation(string $className): string
    {
        return sprintf(
            "Class %s is not a trait, Only traits may be used in 'as' and 'insteadof' statements",
            $className
        );
    }

    /**
     * Adaptation names a trait that exists but is not in the composing class {@code use} list.
     *
     * php-src: Zend/zend_inheritance.c {@code zend_check_trait_usage}
     * ({@code Required Trait %s wasn't added to %s}, #32130).
     */
    public static function requiredNotAdded(string $traitName, string $className): string
    {
        return sprintf(
            "Required Trait %s wasn't added to %s",
            ltrim($traitName, '\\'),
            ltrim($className, '\\'),
        );
    }

    /**
     * Precedence/alias name not a used trait — Zend {@code zend_check_trait_usage}.
     *
     * Declared non-trait → {@see notATraitForAdaptation} (#32129); existing trait not in
     * {@code use} → {@see requiredNotAdded} (#32130); unknown name → {@code Could not find trait}.
     *
     * @return never
     */
    public static function throwUnresolvedAdaptationTrait(
        string $referencedName,
        string $className,
        bool $existsAsTrait,
        ?string $declaredTraitName = null,
        bool $existsAsNonTrait = false,
        ?string $declaredClassName = null,
    ): void {
        if ($existsAsNonTrait) {
            throw new \LogicException(self::notATraitForAdaptation(
                $declaredClassName ?? ltrim($referencedName, '\\')
            ));
        }
        if ($existsAsTrait) {
            throw new \LogicException(self::requiredNotAdded(
                $declaredTraitName ?? $referencedName,
                $className,
            ));
        }
        throw new \LogicException('Could not find trait ' . $referencedName);
    }

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
     * Hooked property trait composition — Zend rejects any hook↔hook or hook↔plain merge (#30009).
     *
     * php-src: Zend/zend_inheritance.c zend_do_traits_property_binding
     * (`colliding_prop->hooks || property_info->hooks`).
     */
    public static function sameHookedProperty(
        string $first,
        string $second,
        string $propertyName,
        string $className,
    ): string {
        return sprintf(
            '%s and %s define the same hooked property ($%s) in the composition of %s. '
            .'Conflict resolution between hooked properties is currently not supported. Class was composed',
            $first,
            $second,
            $propertyName,
            $className,
        );
    }

    /** Class vs trait hooked property — class name first (#30009, zend_inheritance.c). */
    public static function sameHookedClassTraitProperty(
        string $className,
        string $traitName,
        string $propertyName,
    ): string {
        return self::sameHookedProperty($className, $traitName, $propertyName, $className);
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
