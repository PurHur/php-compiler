<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Union type duplicate-member compile check (zend_compile.c).
 *
 * Repeated named/literal arms ({@code int|string|int}, {@code false|null|false},
 * {@code Foo|Bar|Foo}) are compile fatals: {@code Duplicate type X is redundant}.
 *
 * Case-insensitive match; message uses the second occurrence's spelling (leading
 * {@code \} stripped). Exact DNF arm equality (#26606) / subset redundancy (#26607)
 * and semantic overlaps ({@code iterable|array}, {@code object|Class}) are separate checks.
 *
 * php-src: Zend/zend_compile.c — zend_compile_type duplicate type-arm rejection.
 *
 * @see IntersectionTypeMemberCompileCheck for {@code &} member duplicates (#26605)
 * @see RedundantDnfArmCompileCheck for equivalent DNF intersection arms (#26606)
 * @see RedundantTrueFalseUnionCheck for {@code bool|true} / {@code true|false} (#26555)
 */
final class DuplicateUnionMemberCompileCheck
{
    public static function duplicateMessageFor(string $typeName): string
    {
        return sprintf('Duplicate type %s is redundant', $typeName);
    }

    /**
     * First duplicate union member display name (second occurrence spelling), or null.
     */
    public static function findDuplicateMemberName(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            return self::findDuplicateMemberName($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            $seen = [];
            foreach ($type->types as $member) {
                if ($member instanceof Op\Type\Union_ || $member instanceof Op\Type\Nullable) {
                    $nested = self::findDuplicateMemberName($member);
                    if (null !== $nested) {
                        return $nested;
                    }

                    continue;
                }
                // Intersection arms: internal duplicates are {@see IntersectionTypeMemberCompileCheck};
                // exact/subset DNF arm redundancy is a separate issue family.
                if ($member instanceof Op\Type\Intersection) {
                    continue;
                }
                $display = self::memberCanonicalDisplayName($member);
                if (null === $display) {
                    continue;
                }
                $key = strtolower($display);
                if (isset($seen[$key])) {
                    return $display;
                }
                $seen[$key] = true;
            }

            return null;
        }

        return null;
    }

    /**
     * Builtin / class / special type name with leading {@code \} stripped; casing preserved.
     */
    private static function memberCanonicalDisplayName(Op\Type $member): ?string
    {
        if ($member instanceof Op\Type\Never_) {
            return 'never';
        }
        if ($member instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($member instanceof Op\Type\Mixed_) {
            return 'mixed';
        }
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
