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
        ?Variable $propertyMeta = null,
        bool $strict = true,
        ?string $returnCallableName = null
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
        if (!$strict) {
            $scalarConstraints = \PHPCompiler\DnfType::scalarTypeConstraintsFromArms($arms);
            if ([] !== $scalarConstraints) {
                $label = null !== $propertyMeta && null !== $propertyMeta->declaredTypeLabel
                    ? $propertyMeta->declaredTypeLabel
                    : \PHPCompiler\DnfType::formatUnionType($arms);
                $probe = new Variable();
                $probe->copyFrom($resolved);
                try {
                    TypeCheck::coerceUnionValue(
                        $probe,
                        $scalarConstraints,
                        false,
                        $kind,
                        $label,
                        $returnCallableName
                    );
                    $value->copyFrom($probe);

                    return;
                } catch (\TypeError $e) {
                    // fall through to TypeError with union label
                }
            }
        }
        $expected = \PHPCompiler\DnfType::zendTypeErrorLabel(
            \PHPCompiler\DnfType::formatUnionType($arms)
        );
        if ('Property' === $kind && null !== $propertyMeta && null !== $propertyMeta->declaredTypeLabel) {
            throw self::propertyTypeError($propertyMeta, $propertyMeta->declaredTypeLabel, $resolved);
        }
        $ctx = TypeCheck::currentParamErrorContext();
        if (null !== $ctx && 'Argument' === $kind) {
            $ctx->throwExpectedType($expected, $value);
        }
        $given = self::givenTypeLabel($resolved);
        if ('Return value' === $kind) {
            $message = "Return value must be of type {$expected}, {$given} returned";
            if (null !== $returnCallableName && '' !== $returnCallableName) {
                $message = "{$returnCallableName}(): {$message}";
            }

            throw new \TypeError($message);
        }
        throw new \TypeError("{$kind} must be of type {$expected}, {$given} given");
    }

    /**
     * @param array{kind: string, interfaces?: list<string>, name?: string} $arm
     */
    private static function matchesArm(Variable $value, array $arm, Context $context): bool
    {
        return match ($arm['kind']) {
            'null' => Variable::TYPE_NULL === $value->type,
            'literal' => self::matchesLiteralArm($value, $arm['name'], $context),
            'intersection' => self::matchesIntersectionArm($value, $arm['interfaces'], $context),
            default => false,
        };
    }

    /**
     * @param list<string> $interfaceLcs
     */
    private static function matchesIntersectionArm(Variable $value, array $interfaceLcs, Context $context): bool
    {
        $entry = EnumCaseSupport::entryForInstanceOfCheck($value);
        if (null === $entry) {
            if (Variable::TYPE_OBJECT !== $value->type) {
                return false;
            }
            $entry = $value->toObject()->class;
        }
        foreach ($interfaceLcs as $memberLc) {
            if (!InterfaceCheck::entrySatisfiesIntersectionMember($entry, $memberLc, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesLiteralArm(Variable $value, string $name, Context $context): bool
    {
        if ('null' === $name) {
            return Variable::TYPE_NULL === $value->type;
        }

        // Pseudo-types use the same predicates as bare callable/iterable params
        // (zend_type.c IS_CALLABLE / IS_ITERABLE) — not class-name lookup (#25561).
        if ('callable' === $name) {
            return CallableCheck::isCallable($value, $context);
        }
        if ('iterable' === $name) {
            return IterableCheck::isIterable($value, $context);
        }

        if (Variable::TYPE_BOOLEAN !== $value->type) {
            return match ($name) {
                'int' => Variable::TYPE_INTEGER === $value->type,
                'float' => Variable::TYPE_FLOAT === $value->type,
                'string' => Variable::TYPE_STRING === $value->type,
                'array' => Variable::TYPE_ARRAY === $value->type,
                'object' => Variable::TYPE_OBJECT === $value->type,
                default => self::matchesClassNameArm($value, $name, $context),
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
        // zend_execute.c — bool actuals print true/false (#29097).
        return EnumCaseSupport::typeNameForTypeErrorActual($value);
    }

    private static function matchesClassNameArm(Variable $value, string $name, Context $context): bool
    {
        $enumMatch = EnumCaseSupport::valueMatchesInstanceOfClassName($value, $name, $context);
        if (null !== $enumMatch) {
            return $enumMatch;
        }

        return Variable::TYPE_OBJECT === $value->type
            && InterfaceCheck::entryIsInstanceOf($value->toObject()->class, $name, $context);
    }

    private static function enumCaseClassLabel(Variable $value): string
    {
        if (Variable::TYPE_ENUM_CASE !== $value->type) {
            return 'object';
        }

        // Preserve declared case (zend_get_object_type / TypeError) — not strtolower (#25947).
        return ltrim($value->toEnumCase()->enumClass->name, '\\');
    }

    private static function objectClassLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT !== $value->type) {
            return 'object';
        }

        return ltrim($value->toObject()->class->name, '\\');
    }

    private static function propertyTypeError(
        Variable $target,
        string $expectedType,
        Variable $value
    ): \TypeError {
        $expectedType = \PHPCompiler\DnfType::zendTypeErrorLabel($expectedType);
        $propPhrase = TypeCheck::isAssignViaTypedPropertyReference()
            ? 'reference held by property'
            : 'property';
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return new \TypeError(sprintf(
                'Cannot assign %s to %s %s::$%s of type %s',
                self::givenTypeLabel($value),
                $propPhrase,
                $owner->class->name,
                $propName,
                $expectedType
            ));
        }
        $classLc = $target->staticPropertyClassLc;
        if (null !== $classLc && '' !== $propName) {
            $classLabel = $classLc;
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$classLc])) {
                $classLabel = $vm->context->classes[$classLc]->name;
            }

            return new \TypeError(sprintf(
                'Cannot assign %s to %s %s::$%s of type %s',
                self::givenTypeLabel($value),
                $propPhrase,
                $classLabel,
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
