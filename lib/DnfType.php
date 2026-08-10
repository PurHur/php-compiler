<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Op\Type as CfgType;

/**
 * DNF (Disjunctive Normal Form) types: unions of intersections, e.g. (A&B)|null (#3094).
 *
 * @phpstan-type DnfArm array{kind: 'null'}|array{kind: 'intersection', interfaces: list<string>}|array{kind: 'literal', name: string}
 */
final class DnfType
{
    /**
     * @return list<DnfArm>
     */
    public static function armsFromCfgType(
        ?CfgType $type,
        callable $intersectionNames,
        ?callable $intersectionDisplay = null,
        ?callable $referenceName = null
    ): array {
        if (null === $type) {
            return [];
        }
        if ($type instanceof CfgType\Nullable) {
            return array_merge(
                self::armsFromCfgType($type->subtype, $intersectionNames, $intersectionDisplay, $referenceName),
                [['kind' => 'null']]
            );
        }
        if ($type instanceof CfgType\Union_) {
            $arms = [];
            foreach ($type->types as $member) {
                $arms = array_merge(
                    $arms,
                    self::armsFromCfgType($member, $intersectionNames, $intersectionDisplay, $referenceName)
                );
            }

            return self::dedupeArms($arms);
        }
        if ($type instanceof CfgType\Reference) {
            if (null === $referenceName) {
                return [];
            }
            $name = $referenceName($type);
            if (null === $name || '' === $name) {
                return [];
            }
            $display = ltrim($name, '\\');

            return [['kind' => 'literal', 'name' => strtolower($display), 'display' => $display]];
        }
        if ($type instanceof CfgType\Intersection) {
            $ifaces = $intersectionNames($type);
            if ([] === $ifaces) {
                return [];
            }
            $display = null !== $intersectionDisplay
                ? $intersectionDisplay($type)
                : implode('&', $ifaces);

            return [['kind' => 'intersection', 'interfaces' => $ifaces, 'display' => $display]];
        }
        if ($type instanceof CfgType\Never_) {
            return [['kind' => 'literal', 'name' => 'never']];
        }
        if ($type instanceof CfgType\Literal) {
            $nameLc = strtolower($type->name);
            // php-src ZEND_TYPE_IS_ITERABLE_FALLBACK — iterable in unions/nullable
            // expands to Traversable|array (#25562, #25065; Zend/zend_types.h).
            if ('iterable' === $nameLc) {
                return [
                    ['kind' => 'literal', 'name' => 'traversable', 'display' => 'Traversable'],
                    ['kind' => 'literal', 'name' => 'array'],
                ];
            }

            return [['kind' => 'literal', 'name' => $nameLc]];
        }

        return [];
    }

    public static function labelFromCfgType(
        ?CfgType $type,
        callable $intersectionNames,
        ?callable $intersectionDisplay = null,
        ?callable $referenceName = null
    ): string {
        return self::formatUnionType(
            self::armsFromCfgType($type, $intersectionNames, $intersectionDisplay, $referenceName)
        );
    }

    /**
     * @param list<DnfArm> $arms
     */
    public static function hasConstraints(array $arms): bool
    {
        return [] !== $arms;
    }

    /**
     * True when arms need DNF metadata. Simple scalar unions (`int|string`, `?int`)
     * use unionTypeConstraints instead (#6701). Class names, intersections, and
     * iterable→Traversable|array expansions need DnfCheck so arrays match iterable
     * and non-Traversable objects are rejected (#25562).
     *
     * @param list<DnfArm> $arms
     */
    public static function requiresDnfLowering(array $arms): bool
    {
        foreach ($arms as $arm) {
            $kind = $arm['kind'] ?? '';
            if ('intersection' === $kind) {
                return true;
            }
            if ('literal' === $kind && !self::isScalarUnionConstraintName($arm['name'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pure scalar / null / object builtins that map to Variable::TYPE_* union arms.
     */
    private static function isScalarUnionConstraintName(string $name): bool
    {
        return match ($name) {
            'int', 'integer', 'float', 'double', 'bool', 'boolean',
            'string', 'array', 'null', 'true', 'false', 'object' => true,
            default => false,
        };
    }

    /**
     * @param list<DnfArm> $arms
     * @return list<DnfArm>
     */
    private static function dedupeArms(array $arms): array
    {
        $seen = [];
        $out = [];
        foreach ($arms as $arm) {
            $kind = $arm['kind'] ?? '';
            if ('null' === $kind) {
                $key = 'null';
            } elseif ('literal' === $kind) {
                $key = 'literal:'.($arm['name'] ?? '');
            } elseif ('intersection' === $kind) {
                $ifaces = $arm['interfaces'] ?? [];
                sort($ifaces);
                $key = 'intersection:'.implode('&', $ifaces);
            } else {
                $out[] = $arm;

                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $arm;
        }

        return $out;
    }

    /**
     * Scalar/null Variable::TYPE_* arms for weak union coercion (#19525).
     * Class-name / intersection / true|false literal arms are omitted (exact-match only).
     *
     * @param list<DnfArm> $arms
     * @return list<int>
     */
    public static function scalarTypeConstraintsFromArms(array $arms): array
    {
        $out = [];
        foreach ($arms as $arm) {
            $kind = $arm['kind'] ?? '';
            if ('null' === $kind) {
                $out[] = \PHPCompiler\VM\Variable::TYPE_NULL;

                continue;
            }
            if ('literal' !== $kind) {
                continue;
            }
            $mapped = match ($arm['name'] ?? '') {
                'int' => \PHPCompiler\VM\Variable::TYPE_INTEGER,
                'float' => \PHPCompiler\VM\Variable::TYPE_FLOAT,
                'bool' => \PHPCompiler\VM\Variable::TYPE_BOOLEAN,
                'string' => \PHPCompiler\VM\Variable::TYPE_STRING,
                'array' => \PHPCompiler\VM\Variable::TYPE_ARRAY,
                'null' => \PHPCompiler\VM\Variable::TYPE_NULL,
                default => null,
            };
            if (null !== $mapped) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param list<DnfArm> $arms
     */
    public static function formatUnionType(array $arms): string
    {
        $parts = [];
        foreach ($arms as $arm) {
            $part = match ($arm['kind']) {
                'null' => 'null',
                'intersection' => $arm['display'] ?? implode('&', $arm['interfaces']),
                'literal' => $arm['display'] ?? $arm['name'],
            };
            if (\count($arms) > 1 && 'intersection' === $arm['kind']) {
                $part = '(' . $part . ')';
            }
            $parts[] = $part;
        }

        // Match zend_type_to_string order + simple T|null → ?T (TypeError / Reflection, #29960).
        return self::zendCanonicalUnionLabel($parts);
    }

    /**
     * Zend zend_type_to_string() / ReflectionUnionType member order
     * (Zend/zend_compile.c zend_type_to_string_resolved; php_reflection.c getTypes).
     *
     * List/complex types (classes, intersections, self/parent/static names) stay in
     * declaration order first; pure builtins follow the MAY_BE_* mask walk order.
     *
     * @return array<string, int>
     */
    public static function zendBuiltinUnionOrder(): array
    {
        return [
            'static' => 0,
            'callable' => 1,
            'object' => 2,
            'array' => 3,
            'string' => 4,
            'int' => 5,
            'integer' => 5,
            'float' => 6,
            'double' => 6,
            'bool' => 7,
            'boolean' => 7,
            // false before true matches zend_type_to_string_resolved; mutually exclusive with bool.
            'false' => 8,
            'true' => 9,
            'void' => 10,
            'never' => 11,
            'null' => 12,
        ];
    }

    /**
     * @param list<string> $memberNames
     * @return list<string>
     */
    public static function zendSortUnionMemberNames(array $memberNames): array
    {
        if (\count($memberNames) <= 1) {
            return $memberNames;
        }
        $order = self::zendBuiltinUnionOrder();
        $list = [];
        $builtins = [];
        foreach ($memberNames as $name) {
            if (isset($order[strtolower($name)])) {
                $builtins[] = $name;
            } else {
                $list[] = $name;
            }
        }
        usort(
            $builtins,
            static fn (string $a, string $b): int => $order[strtolower($a)] <=> $order[strtolower($b)]
        );

        return array_merge($list, $builtins);
    }

    /**
     * @param list<string> $memberNames
     */
    public static function zendCanonicalUnionLabel(array $memberNames): string
    {
        if ([] === $memberNames) {
            return '';
        }
        $sorted = self::zendSortUnionMemberNames($memberNames);
        // Simple T|null → ?T (zend_type pretty-print / property TypeError, #25622).
        if (2 === \count($sorted)) {
            $a = strtolower($sorted[0]);
            $b = strtolower($sorted[1]);
            if ('null' === $b && 'null' !== $a && !str_contains($sorted[0], '|') && !str_contains($sorted[0], '&')) {
                return '?'.$sorted[0];
            }
            if ('null' === $a && 'null' !== $b && !str_contains($sorted[1], '|') && !str_contains($sorted[1], '&')) {
                return '?'.$sorted[1];
            }
        }

        return implode('|', $sorted);
    }

    /**
     * Property / typed-slot TypeError expected-type display (php-src zend_type pretty-print).
     * Accepts labels already stored as `int|null` and renders `?int` (#25622).
     */
    public static function zendTypeErrorLabel(string $label): string
    {
        if ('' === $label || str_starts_with($label, '?')) {
            return $label;
        }
        $parts = explode('|', $label);
        if (2 !== \count($parts)) {
            return $label;
        }
        $a = trim($parts[0]);
        $b = trim($parts[1]);
        if ('' === $a || '' === $b || str_contains($a, '&') || str_contains($b, '&')) {
            return $label;
        }
        $aLc = strtolower($a);
        $bLc = strtolower($b);
        if ('null' === $bLc && 'null' !== $aLc) {
            return '?'.$a;
        }
        if ('null' === $aLc && 'null' !== $bLc) {
            return '?'.$b;
        }

        return $label;
    }
}
