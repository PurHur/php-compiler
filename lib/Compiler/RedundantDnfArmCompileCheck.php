<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * DNF union arm equivalence compile check (zend_compile.c).
 *
 * Two intersection arms with the same member set (order-insensitive, case-insensitive,
 * leading {@code \} ignored) are a compile fatal:
 * {@code Type B&A is redundant with type A&B}.
 *
 * Display labels keep each arm's written member order and spelling (second arm first in
 * the message). Does not claim subset / “more restrictive” redundancy — that is a
 * separate issue family.
 *
 * php-src: Zend/zend_compile.c — DNF type compilation / arm uniqueness.
 *
 * @see IntersectionTypeMemberCompileCheck for {@code A&B&A} (#26605)
 * @see DuplicateUnionMemberCompileCheck for non-intersection union duplicates (#26556)
 */
final class RedundantDnfArmCompileCheck
{
    public static function messageFor(string $redundantLabel, string $earlierLabel): string
    {
        return sprintf('Type %s is redundant with type %s', $redundantLabel, $earlierLabel);
    }

    /**
     * First redundant DNF arm pair as {@code [redundantDisplay, earlierDisplay]}, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function findRedundantArmPair(?Op\Type $type): ?array
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            return self::findRedundantArmPair($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            $seen = [];
            foreach ($type->types as $member) {
                if ($member instanceof Op\Type\Union_ || $member instanceof Op\Type\Nullable) {
                    $nested = self::findRedundantArmPair($member);
                    if (null !== $nested) {
                        return $nested;
                    }

                    continue;
                }
                if (!$member instanceof Op\Type\Intersection) {
                    continue;
                }
                $display = self::intersectionDisplayLabel($member);
                $key = self::intersectionCanonicalKey($member);
                if (null === $display || null === $key) {
                    $nested = self::findRedundantArmPair($member);
                    if (null !== $nested) {
                        return $nested;
                    }

                    continue;
                }
                if (isset($seen[$key])) {
                    return [$display, $seen[$key]];
                }
                $seen[$key] = $display;
            }

            return null;
        }

        return null;
    }

    /**
     * Written member order, leading {@code \} stripped; casing preserved.
     */
    private static function intersectionDisplayLabel(Op\Type\Intersection $arm): ?string
    {
        $parts = [];
        foreach ($arm->types as $member) {
            $name = self::memberCanonicalDisplayName($member);
            if (null === $name) {
                return null;
            }
            $parts[] = $name;
        }
        if ([] === $parts) {
            return null;
        }

        return implode('&', $parts);
    }

    /**
     * Order-insensitive set key for equivalence (sorted lowercase names).
     */
    private static function intersectionCanonicalKey(Op\Type\Intersection $arm): ?string
    {
        $parts = [];
        foreach ($arm->types as $member) {
            $name = self::memberCanonicalDisplayName($member);
            if (null === $name) {
                return null;
            }
            $parts[] = strtolower($name);
        }
        if ([] === $parts) {
            return null;
        }
        sort($parts, SORT_STRING);

        return implode("\0", $parts);
    }

    private static function memberCanonicalDisplayName(Op\Type $member): ?string
    {
        if ($member instanceof Op\Type\Literal) {
            return ltrim($member->name, '\\');
        }
        if ($member instanceof Op\Type\Reference) {
            $name = self::referenceName($member);

            return null !== $name ? ltrim($name, '\\') : null;
        }

        return null;
    }

    private static function referenceName(Op\Type\Reference $type): ?string
    {
        return self::operandString($type->declaration);
    }

    private static function operandString(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::operandString($op->name);
        }

        return null;
    }
}
