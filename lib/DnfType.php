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
     * Zend zend_type_to_string() pure-mask member order (PHP-8.2 Zend/zend_compile.c).
     *
     * @param list<string> $memberNames
     */
    public static function zendCanonicalUnionLabel(array $memberNames): string
    {
        if ([] === $memberNames) {
            return '';
        }
        if (1 === \count($memberNames)) {
            return $memberNames[0];
        }
        $order = [
            'static' => 0,
            'callable' => 1,
            'object' => 2,
            'array' => 3,
            'string' => 4,
            'int' => 5,
            'float' => 6,
            'bool' => 7,
            'void' => 8,
            'never' => 9,
            'null' => 10,
            'true' => 11,
            'false' => 12,
        ];
        $sorted = $memberNames;
        usort(
            $sorted,
            static fn (string $a, string $b): int => ($order[strtolower($a)] ?? 99) <=> ($order[strtolower($b)] ?? 99)
        );

        return implode('|', $sorted);
    }
}
