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

            return [['kind' => 'literal', 'name' => strtolower(ltrim($name, '\\'))]];
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
     * @param list<DnfArm> $arms
     */
    public static function formatUnionType(array $arms): string
    {
        $parts = [];
        foreach ($arms as $arm) {
            $part = match ($arm['kind']) {
                'null' => 'null',
                'intersection' => $arm['display'] ?? implode('&', $arm['interfaces']),
                'literal' => $arm['name'],
            };
            if (\count($arms) > 1 && 'intersection' === $arm['kind']) {
                $part = '(' . $part . ')';
            }
            $parts[] = $part;
        }

        return implode('|', $parts);
    }
}
