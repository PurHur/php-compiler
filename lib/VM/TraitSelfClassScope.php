<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Trait method scope rebinding — composing class, not trait (#18879, #18878, Zend/zend_traits.c).
 *
 * self::class and parent:: resolve against the using class hierarchy; other self:: members
 * (e.g. trait private constants) keep trait scope.
 */
final class TraitSelfClassScope
{
    /**
     * Lowercase composing class when a trait method runs in a user class (#18878).
     */
    public static function resolveComposingClassLc(
        ?string $funcClassLc,
        bool $funcClassIsTrait,
        ?string $calledClassLc,
        string $fallbackLc,
    ): string {
        if (!$funcClassIsTrait || null === $funcClassLc || '' === $funcClassLc) {
            return strtolower(ltrim($fallbackLc, '\\'));
        }
        $funcLc = strtolower(ltrim($funcClassLc, '\\'));
        $calledLc = null !== $calledClassLc && '' !== $calledClassLc
            ? strtolower(ltrim($calledClassLc, '\\'))
            : null;
        if (null !== $calledLc && $calledLc !== $funcLc) {
            return $calledLc;
        }

        return strtolower(ltrim($fallbackLc, '\\'));
    }

    public static function resolveSelfClassName(
        ?string $funcClassLc,
        bool $funcClassIsTrait,
        ?string $calledClassLc,
        string $fallbackDisplayName,
        ?callable $displayNameForLc = null
    ): string {
        if (!$funcClassIsTrait || null === $funcClassLc || '' === $funcClassLc) {
            return $fallbackDisplayName;
        }
        $funcLc = strtolower(ltrim($funcClassLc, '\\'));
        $calledLc = null !== $calledClassLc && '' !== $calledClassLc
            ? strtolower(ltrim($calledClassLc, '\\'))
            : null;
        if (null !== $calledLc && $calledLc !== $funcLc) {
            return null !== $displayNameForLc
                ? $displayNameForLc($calledLc)
                : $calledClassLc;
        }

        return $fallbackDisplayName;
    }
}
