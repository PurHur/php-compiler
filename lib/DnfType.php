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
    public static function armsFromCfgType(?CfgType $type, callable $intersectionNames): array
    {
        if (null === $type) {
            return [];
        }
        if ($type instanceof CfgType\Nullable) {
            return array_merge(
                self::armsFromCfgType($type->subtype, $intersectionNames),
                [['kind' => 'null']]
            );
        }
        if ($type instanceof CfgType\Union_) {
            $arms = [];
            foreach ($type->types as $member) {
                $arms = array_merge($arms, self::armsFromCfgType($member, $intersectionNames));
            }

            return $arms;
        }
        if ($type instanceof CfgType\Intersection) {
            $ifaces = $intersectionNames($type);
            if ([] === $ifaces) {
                return [];
            }

            return [['kind' => 'intersection', 'interfaces' => $ifaces]];
        }
        if ($type instanceof CfgType\Literal) {
            return [['kind' => 'literal', 'name' => strtolower($type->name)]];
        }

        return [];
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
            $parts[] = match ($arm['kind']) {
                'null' => 'null',
                'intersection' => implode('&', $arm['interfaces']),
                'literal' => $arm['name'],
            };
        }

        return implode('|', $parts);
    }
}
