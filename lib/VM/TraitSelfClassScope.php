<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Trait method scope rebinding — composing class, not trait (#19629, #18879, #18878, Zend/zend_traits.c).
 *
 * After trait flatten, self:: (class, method, constant) and parent:: bind to the class that
 * `use`d the trait — not the trait name and not the late-static called class.
 */
final class TraitSelfClassScope
{
    /**
     * Lowercase composing class when a trait method runs in a user class (#18878, #19629).
     *
     * @param null|callable(string, string): ?string $traitSourceFor classLc, methodLc → trait FQCN
     * @param null|callable(string): ?string         $parentOf       classLc → parentLc
     * @param null|callable(string): bool            $isTraitClass   classLc → is trait
     */
    public static function resolveComposingClassLc(
        ?string $funcClassLc,
        bool $funcClassIsTrait,
        ?string $calledClassLc,
        string $fallbackLc,
        ?string $methodLc = null,
        ?callable $traitSourceFor = null,
        ?callable $parentOf = null,
        ?callable $isTraitClass = null,
    ): string {
        if (!$funcClassIsTrait || null === $funcClassLc || '' === $funcClassLc) {
            return strtolower(ltrim($fallbackLc, '\\'));
        }
        $traitLc = strtolower(ltrim($funcClassLc, '\\'));
        $fallbackNorm = strtolower(ltrim($fallbackLc, '\\'));
        $calledLc = null !== $calledClassLc && '' !== $calledClassLc
            ? strtolower(ltrim($calledClassLc, '\\'))
            : null;

        // Direct call on the trait (T::method) — self stays the trait (#18879).
        if (null !== $calledLc && $calledLc === $traitLc) {
            return $traitLc;
        }
        if (null !== $calledLc && null !== $isTraitClass && $isTraitClass($calledLc)) {
            return $calledLc;
        }

        $start = $calledLc ?? $fallbackNorm;
        if (null !== $methodLc && '' !== $methodLc && null !== $traitSourceFor && null !== $parentOf) {
            $methodLc = strtolower($methodLc);
            $cur = $start;
            $guard = 0;
            while (null !== $cur && '' !== $cur && $guard++ < 256) {
                if ($cur === $traitLc) {
                    break;
                }
                if (null !== $isTraitClass && $isTraitClass($cur)) {
                    break;
                }
                $src = $traitSourceFor($cur, $methodLc);
                if (null !== $src && '' !== $src && strtolower(ltrim($src, '\\')) === $traitLc) {
                    return $cur;
                }
                $cur = $parentOf($cur);
            }
        }

        // No method table walk (or sources missing): prefer non-trait called/fallback over the trait.
        if (null !== $calledLc && $calledLc !== $traitLc) {
            if (null === $isTraitClass || !$isTraitClass($calledLc)) {
                return $calledLc;
            }
        }
        if ($fallbackNorm !== $traitLc && (null === $isTraitClass || !$isTraitClass($fallbackNorm))) {
            return $fallbackNorm;
        }

        return $traitLc;
    }

    /**
     * @param null|callable(string): string          $displayNameForLc
     * @param null|callable(string, string): ?string $traitSourceFor
     * @param null|callable(string): ?string         $parentOf
     * @param null|callable(string): bool            $isTraitClass
     */
    public static function resolveSelfClassName(
        ?string $funcClassLc,
        bool $funcClassIsTrait,
        ?string $calledClassLc,
        string $fallbackDisplayName,
        ?callable $displayNameForLc = null,
        ?string $methodLc = null,
        ?callable $traitSourceFor = null,
        ?callable $parentOf = null,
        ?callable $isTraitClass = null,
    ): string {
        if (!$funcClassIsTrait || null === $funcClassLc || '' === $funcClassLc) {
            return $fallbackDisplayName;
        }
        $composingLc = self::resolveComposingClassLc(
            $funcClassLc,
            true,
            $calledClassLc,
            strtolower(ltrim($fallbackDisplayName, '\\')),
            $methodLc,
            $traitSourceFor,
            $parentOf,
            $isTraitClass,
        );

        return null !== $displayNameForLc
            ? $displayNameForLc($composingLc)
            : $composingLc;
    }
}
