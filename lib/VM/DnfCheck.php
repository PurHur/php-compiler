<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Runtime checks for DNF type hints (union of intersections, etc.) (#3094).
 */
final class DnfCheck
{
    /**
     * @param list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}> $arms
     */
    public static function assertMatches(
        Variable $value,
        array $arms,
        Context $context,
        string $kind = 'Argument',
        ?Variable $propertyMeta = null
    ): void {
        if ([] === $arms) {
            return;
        }
        $resolved = $value->resolveIndirect();
        foreach ($arms as $arm) {
            if (self::matchesArm($resolved, $arm, $context)) {
                return;
            }
        }
        $expected = \PHPCompiler\DnfType::formatUnionType($arms);
        if ('Property' === $kind && null !== $propertyMeta && null !== $propertyMeta->declaredTypeLabel) {
            throw self::propertyTypeError($propertyMeta, $propertyMeta->declaredTypeLabel, $resolved);
        }
        $given = self::givenTypeLabel($resolved);
        throw new \TypeError("{$kind} must be of type {$expected}, {$given} given");
    }

    /**
     * @param array{kind: string, interfaces?: list<string>, name?: string} $arm
     */
    private static function matchesArm(Variable $value, array $arm, Context $context): bool
    {
        return match ($arm['kind']) {
            'null' => Variable::TYPE_NULL === $value->type,
            'literal' => self::matchesLiteralArm($value, $arm['name']),
            'intersection' => self::matchesIntersectionArm($value, $arm['interfaces'], $context),
            default => false,
        };
    }

    /**
     * @param list<string> $interfaceLcs
     */
    private static function matchesIntersectionArm(Variable $value, array $interfaceLcs, Context $context): bool
    {
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }
        $entry = $value->toObject()->class;
        foreach ($interfaceLcs as $ifaceLc) {
            if (!InterfaceCheck::entryImplements($entry, $ifaceLc, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesLiteralArm(Variable $value, string $name): bool
    {
        if ('null' === $name) {
            return Variable::TYPE_NULL === $value->type;
        }

        if (Variable::TYPE_BOOLEAN !== $value->type) {
            return match ($name) {
                'int' => Variable::TYPE_INTEGER === $value->type,
                'float' => Variable::TYPE_FLOAT === $value->type,
                'string' => Variable::TYPE_STRING === $value->type,
                'array' => Variable::TYPE_ARRAY === $value->type,
                'object' => Variable::TYPE_OBJECT === $value->type,
                default => false,
            };
        }

        return match ($name) {
            'true' => $value->toBool(),
            'false' => !$value->toBool(),
            'bool' => true,
            default => false,
        };
    }

    private static function givenTypeLabel(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => self::objectClassLabel($value),
            default => 'mixed',
        };
    }

    private static function objectClassLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT !== $value->type) {
            return 'object';
        }

        return strtolower(ltrim($value->toObject()->class->name, '\\'));
    }

    private static function propertyTypeError(
        Variable $target,
        string $expectedType,
        Variable $value
    ): \TypeError {
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return new \TypeError(sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                self::givenTypeLabel($value),
                $owner->class->name,
                $propName,
                $expectedType
            ));
        }

        return new \TypeError(sprintf(
            'Property must be of type %s, %s given',
            $expectedType,
            self::givenTypeLabel($value)
        ));
    }
}
