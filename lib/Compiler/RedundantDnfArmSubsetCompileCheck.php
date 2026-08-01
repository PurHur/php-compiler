<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Zend DNF arm subset / "more restrictive" compile check (#26607).
 *
 * When one intersection (or class) arm's member set is a proper subset of another
 * arm's, Zend rejects the larger arm:
 * {@code Type A&B&C is redundant as it is more restrictive than type A&B}.
 * Also covers {@code A|(A&B)} → {@code Type A&B is redundant as it is more restrictive than type A}.
 *
 * Exact/commutative arm equality ({@code Type X is redundant with type Y}) is a
 * separate check (#26606) — this class only emits the "more restrictive" fatal.
 *
 * php-src: Zend/zend_compile.c — {@code zend_are_intersection_types_redundant},
 * {@code zend_is_intersection_type_redundant_by_single_type}.
 *
 * @see DuplicateUnionMemberCompileCheck for named duplicate union arms
 * @see IntersectionTypeMemberCompileCheck for {@code &} member duplicates
 */
final class RedundantDnfArmSubsetCompileCheck
{
    /** Builtin / special names that are not class-list arms for this check. */
    private const NON_CLASS_LITERALS = [
        'int' => true,
        'float' => true,
        'string' => true,
        'bool' => true,
        'array' => true,
        'object' => true,
        'callable' => true,
        'iterable' => true,
        'mixed' => true,
        'void' => true,
        'never' => true,
        'null' => true,
        'false' => true,
        'true' => true,
    ];

    public static function moreRestrictiveMessage(string $largerDisplay, string $smallerDisplay): string
    {
        return sprintf(
            'Type %s is redundant as it is more restrictive than type %s',
            $largerDisplay,
            $smallerDisplay
        );
    }

    /**
     * Zend-shaped fatal message for a proper-subset DNF arm pair, or null if valid.
     */
    public static function findRedundantMessage(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            return self::findRedundantMessage($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($member instanceof Op\Type\Union_ || $member instanceof Op\Type\Nullable) {
                    $nested = self::findRedundantMessage($member);
                    if (null !== $nested) {
                        return $nested;
                    }
                }
            }

            return self::findRedundantAmongArms(self::collectClassArms($type));
        }

        return null;
    }

    /**
     * @param list<array{display: string, keys: array<string, true>}> $arms
     */
    private static function findRedundantAmongArms(array $arms): ?string
    {
        $n = \count($arms);
        // Match Zend's incremental scan: when arm i is added, compare against 0..i-1.
        for ($i = 1; $i < $n; ++$i) {
            for ($j = 0; $j < $i; ++$j) {
                $msg = self::compareArmPair($arms[$i], $arms[$j]);
                if (null !== $msg) {
                    return $msg;
                }
            }
        }

        return null;
    }

    /**
     * @param array{display: string, keys: array<string, true>} $a
     * @param array{display: string, keys: array<string, true>} $b
     */
    private static function compareArmPair(array $a, array $b): ?string
    {
        $aCount = \count($a['keys']);
        $bCount = \count($b['keys']);
        if ($aCount === $bCount) {
            // Exact / commutative equality → sibling #26606 ("redundant with").
            return null;
        }
        if ($aCount < $bCount) {
            $smaller = $a;
            $larger = $b;
        } else {
            $smaller = $b;
            $larger = $a;
        }
        foreach ($smaller['keys'] as $key => $_) {
            if (!isset($larger['keys'][$key])) {
                return null;
            }
        }

        return self::moreRestrictiveMessage($larger['display'], $smaller['display']);
    }

    /**
     * Class / intersection arms in source order (null and scalar arms skipped).
     *
     * @return list<array{display: string, keys: array<string, true>}>
     */
    private static function collectClassArms(Op\Type\Union_ $union): array
    {
        $arms = [];
        foreach ($union->types as $member) {
            if ($member instanceof Op\Type\Union_ || $member instanceof Op\Type\Nullable) {
                continue;
            }
            $arm = self::armFromType($member);
            if (null !== $arm) {
                $arms[] = $arm;
            }
        }

        return $arms;
    }

    /**
     * @return array{display: string, keys: array<string, true>}|null
     */
    private static function armFromType(Op\Type $type): ?array
    {
        if ($type instanceof Op\Type\Intersection) {
            $displays = [];
            $keys = [];
            foreach ($type->types as $member) {
                $display = self::memberCanonicalDisplayName($member);
                if (null === $display) {
                    continue;
                }
                $displays[] = $display;
                $keys[strtolower($display)] = true;
            }
            if (\count($keys) < 1) {
                return null;
            }

            return ['display' => implode('&', $displays), 'keys' => $keys];
        }

        $display = self::memberCanonicalDisplayName($type);
        if (null === $display) {
            return null;
        }
        $lc = strtolower($display);
        if (isset(self::NON_CLASS_LITERALS[$lc])) {
            return null;
        }

        return ['display' => $display, 'keys' => [$lc => true]];
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
