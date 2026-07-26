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

            return $arms;
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
            return [['kind' => 'literal', 'name' => strtolower($type->name)]];
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
     * True when arms need DNF metadata (intersection members). Simple scalar unions
     * (`int|string`, `?int`) use unionTypeConstraints instead (#6701).
     *
     * @param list<DnfArm> $arms
     */
    public static function requiresDnfLowering(array $arms): bool
    {
        foreach ($arms as $arm) {
            if (($arm['kind'] ?? '') === 'intersection') {
                return true;
            }
        }

        return false;
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

        return implode('|', $parts);
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

        return implode('|', self::zendSortUnionMemberNames($memberNames));
    }
}
