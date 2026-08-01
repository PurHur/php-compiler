<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Intersection type compile checks (zend_compile.c).
 *
 * - Non-class/interface members (#26401): {@code Type X cannot be part of an intersection type}.
 * - Duplicate members (#26605): {@code Duplicate type X is redundant}.
 *
 * php-src: Zend/zend_compile.c — only class/interface types may appear in `&` intersections;
 * scalars, void/never/mixed/iterable/callable/object, true/false/null, and self/static/parent
 * are illegal; the same class/interface name must not appear twice (case-insensitive, leading
 * `\` ignored; message uses the second occurrence's spelling).
 */
final class IntersectionTypeMemberCompileCheck
{
    /** Builtin / special type names illegal inside an intersection (Zend). */
    private const INVALID_LITERAL_NAMES = [
        'int' => 'int',
        'float' => 'float',
        'string' => 'string',
        'bool' => 'bool',
        'array' => 'array',
        'object' => 'object',
        'callable' => 'callable',
        'iterable' => 'Traversable|array',
        'mixed' => 'mixed',
        'void' => 'void',
        'never' => 'never',
        'null' => 'null',
        'false' => 'false',
        'true' => 'true',
        'self' => 'self',
        'static' => 'static',
        'parent' => 'parent',
    ];

    public static function messageFor(string $typeName): string
    {
        return sprintf('Type %s cannot be part of an intersection type', $typeName);
    }

    public static function duplicateMessageFor(string $typeName): string
    {
        return sprintf('Duplicate type %s is redundant', $typeName);
    }

    /**
     * First illegal intersection member display name, or null when all intersections are valid.
     */
    public static function findInvalidMemberName(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            return self::findInvalidMemberName($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                $invalid = self::findInvalidMemberName($member);
                if (null !== $invalid) {
                    return $invalid;
                }
            }

            return null;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                $invalid = self::invalidIntersectionMemberName($member);
                if (null !== $invalid) {
                    return $invalid;
                }
                // Nested DNF (parenthesized intersection arms) — recurse for safety.
                $nested = self::findInvalidMemberName($member);
                if (null !== $nested) {
                    return $nested;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * First duplicate intersection member display name (second occurrence spelling), or null.
     *
     * Does not claim DNF arm redundancy {@code (A&B)|(A&B)} — see {@see RedundantDnfArmCompileCheck} (#26606).
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
            foreach ($type->types as $member) {
                $dup = self::findDuplicateMemberName($member);
                if (null !== $dup) {
                    return $dup;
                }
            }

            return null;
        }
        if ($type instanceof Op\Type\Intersection) {
            $seen = [];
            foreach ($type->types as $member) {
                $display = self::memberCanonicalDisplayName($member);
                if (null === $display) {
                    // Nested intersection / unexpected shape — recurse.
                    $nested = self::findDuplicateMemberName($member);
                    if (null !== $nested) {
                        return $nested;
                    }
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
     * Class/interface member name with leading {@code \} stripped; casing preserved.
     */
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

    private static function invalidIntersectionMemberName(Op\Type $member): ?string
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
            return self::INVALID_LITERAL_NAMES[strtolower(ltrim($member->name, '\\'))] ?? null;
        }
        if ($member instanceof Op\Type\Reference) {
            $name = self::referenceName($member);
            if (null === $name) {
                return null;
            }
            $lc = strtolower(ltrim($name, '\\'));

            return self::INVALID_LITERAL_NAMES[$lc] ?? null;
        }
        // Union inside an intersection (unexpected shape) — Zend would reject the union type text.
        if ($member instanceof Op\Type\Union_) {
            $parts = [];
            foreach ($member->types as $arm) {
                $armName = self::memberDisplayName($arm);
                if (null !== $armName) {
                    $parts[] = $armName;
                }
            }
            if ([] !== $parts) {
                return implode('|', $parts);
            }
        }

        return null;
    }

    private static function memberDisplayName(Op\Type $type): ?string
    {
        if ($type instanceof Op\Type\Never_) {
            return 'never';
        }
        if ($type instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($type instanceof Op\Type\Mixed_) {
            return 'mixed';
        }
        if ($type instanceof Op\Type\Literal) {
            $lc = strtolower(ltrim($type->name, '\\'));

            return self::INVALID_LITERAL_NAMES[$lc] ?? ltrim($type->name, '\\');
        }
        if ($type instanceof Op\Type\Reference) {
            $name = self::referenceName($type);

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
