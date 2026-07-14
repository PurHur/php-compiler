<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * self::class in trait methods — composing class, not trait name (#18879, Zend/zend_traits.c).
 *
 * Other self:: members (e.g. trait private constants) keep trait scope; only ::class rebinding here.
 */
final class TraitSelfClassScope
{
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
