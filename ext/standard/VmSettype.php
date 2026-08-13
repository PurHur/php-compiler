<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * settype() conversions (ext/standard/type.c php_settype / Zend convert_to_*).
 */
final class VmSettype
{
    public static function apply(Variable $slot, string $typeName, ?Frame $frame = null): void
    {
        TypedPropertyCheck::assertWritableByReference($slot);
        $type = strtolower($typeName);
        $value = $slot->resolveIndirect();
        if ('string' === $type && VmScalarType::isEnumCaseVariable($value)) {
            $className = EnumCaseSupport::typeNameForVariable($value);
            $empty = new Variable();
            $empty->string('');
            self::assignSettypeResult($slot, $empty, $type, $frame);
            throw new \Error(
                'Object of class '.$className.' could not be converted to string'
            );
        }
        $result = new Variable();

        switch ($type) {
            case 'integer':
            case 'int':
                self::toInteger($result, $value, $frame);
                break;
            case 'double':
            case 'float':
                self::toFloat($result, $value, $frame);
                break;
            case 'string':
                self::toString($result, $value, $frame);
                break;
            case 'bool':
            case 'boolean':
                if (VmScalarType::isEnumCaseVariable($value)) {
                    $result->bool(true);
                } else {
                    $result->bool(boolval::isTruthy($value));
                }
                break;
            case 'array':
                $result->array(self::toArray($value, $frame));
                break;
            case 'null':
                $result->null();
                break;
            case 'object':
                if (VmScalarType::isEnumCaseVariable($value)) {
                    $result->copyFrom($value);
                    break;
                }
                self::toObject($result, $value, $frame);
                break;
            case 'resource':
                throw new \ValueError('Cannot convert to resource type');
            default:
                throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        self::assignSettypeResult($slot, $result, $type, $frame);
    }

    /**
     * Zend php_settype on typed property references: strict_types rejects incompatible
     * target types; non-strict weak-coerces scalars (type.c, #11508, #14218).
     */
    private static function assignSettypeResult(
        Variable $slot,
        Variable $result,
        string $requestedType,
        ?Frame $frame = null,
    ): void {
        $typeMeta = self::resolveTypedPropertyMetadata($slot, $frame);
        if (null === $typeMeta) {
            $slot->copyFrom($result);

            return;
        }
        $strict = self::callerStrictTypes($frame);
        if ($strict && !self::settypeTargetMatchesDeclaredType($requestedType, $typeMeta)) {
            throw self::settypeTypedPropertyReferenceError(
                $slot,
                $typeMeta,
                self::settypeTypeLabel($requestedType),
            );
        }
        $probe = new Variable();
        $probe->copyFrom($result);
        self::bindPropertyTypeMetadata($probe, $typeMeta);
        try {
            TypeCheck::coercePropertyWrite($probe, $strict);
        } catch (\TypeError) {
            throw self::settypeTypedPropertyReferenceError(
                $slot,
                $typeMeta,
                self::valueTypeLabelForReference($result),
            );
        }
        if (null !== $typeMeta->dnfArms && null !== $frame?->vmContext) {
            DnfCheck::assertMatches($probe, $typeMeta->dnfArms, $frame->vmContext, 'Property', $typeMeta);
        }
        $slot->copyFrom($probe);
    }

    private static function callerStrictTypes(?Frame $frame): bool
    {
        if (null === $frame) {
            return false;
        }
        if (isset($frame->parent) && null !== $frame->parent->block) {
            return $frame->parent->block->strictTypes;
        }
        if (null !== $frame->block) {
            return $frame->block->strictTypes;
        }

        return false;
    }

    private static function settypeTargetMatchesDeclaredType(string $requestedType, Variable $typeMeta): bool
    {
        $meta = $typeMeta->resolveIndirect();
        $requested = strtolower($requestedType);
        if (null !== $meta->classConstraint && '' !== $meta->classConstraint) {
            return 'object' === $requested;
        }
        $constraint = $meta->typeConstraint;
        if (null === $constraint) {
            return true;
        }

        return match ($constraint) {
            Variable::TYPE_INTEGER => \in_array($requested, ['int', 'integer'], true),
            Variable::TYPE_FLOAT => \in_array($requested, ['float', 'double'], true),
            Variable::TYPE_BOOLEAN => \in_array($requested, ['bool', 'boolean'], true),
            Variable::TYPE_STRING => 'string' === $requested,
            Variable::TYPE_ARRAY => 'array' === $requested,
            Variable::TYPE_NULL => 'null' === $requested,
            Variable::TYPE_OBJECT => 'object' === $requested,
            default => false,
        };
    }

    private static function settypeTypeLabel(string $typeName): string
    {
        return match (strtolower($typeName)) {
            'integer', 'int' => 'int',
            'double', 'float' => 'float',
            'boolean', 'bool' => 'bool',
            'string' => 'string',
            'array' => 'array',
            'null' => 'null',
            'object' => 'object',
            default => $typeName,
        };
    }

    private static function valueTypeLabelForReference(Variable $value): string
    {
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $resolved->type) {
            return $resolved->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return $resolved->toObject()->class->name;
        }

        return TypeCheck::typeNameForConstraint($resolved->type);
    }

    private static function settypeTypedPropertyReferenceError(
        Variable $slot,
        Variable $typeMeta,
        string $assignedTypeLabel,
    ): \TypeError {
        $meta = $typeMeta->resolveIndirect();
        $expected = $meta->declaredTypeLabel ?? TypeCheck::typeNameForConstraint(
            $meta->typeConstraint ?? Variable::TYPE_INTEGER,
            $meta->literalBoolType,
        );
        $expected = \PHPCompiler\DnfType::zendTypeErrorLabel($expected);
        $resolved = $slot->resolveIndirect();
        $propName = $slot->objectPropertyName ?? $resolved->objectPropertyName ?? 'property';
        $classLc = $slot->staticPropertyClassLc ?? $resolved->staticPropertyClassLc;
        if (null !== $classLc && null !== $propName) {
            $classLabel = $classLc;
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$classLc])) {
                $classLabel = $vm->context->classes[$classLc]->name;
            }

            return new \TypeError(sprintf(
                'Cannot assign %s to reference held by property %s::$%s of type %s',
                $assignedTypeLabel,
                $classLabel,
                $propName,
                $expected,
            ));
        }
        $owner = $slot->objectPropertyOwner ?? $resolved->objectPropertyOwner;

        return new \TypeError(sprintf(
            'Cannot assign %s to reference held by property %s::$%s of type %s',
            $assignedTypeLabel,
            $owner->class->name,
            $propName,
            $expected,
        ));
    }

    private static function bindPropertyTypeMetadata(Variable $dest, Variable $typeMeta): void
    {
        $resolved = $dest->resolveIndirect();
        $meta = $typeMeta->resolveIndirect();
        $resolved->typeConstraint = $meta->typeConstraint;
        $resolved->classConstraint = $meta->classConstraint;
        $resolved->literalBoolType = $meta->literalBoolType;
        $resolved->unionTypeConstraints = $meta->unionTypeConstraints;
        $resolved->declaredTypeLabel = $meta->declaredTypeLabel;
        $resolved->genericArrayTypeSpec = $meta->genericArrayTypeSpec;
        $resolved->dnfArms = $meta->dnfArms;
    }

    private static function resolveTypedPropertyMetadata(Variable $slot, ?Frame $frame): ?Variable
    {
        $resolved = $slot->resolveIndirect();
        if (self::variableHasDeclaredType($resolved)) {
            return $resolved;
        }
        if (self::variableHasDeclaredType($slot)) {
            return $slot;
        }
        $owner = $slot->objectPropertyOwner ?? $resolved->objectPropertyOwner;
        $propName = $slot->objectPropertyName ?? $resolved->objectPropertyName;
        if (null !== $owner && null !== $propName) {
            $meta = self::instancePropertyTypeMeta($owner, $propName);
            if (null !== $meta) {
                return $meta;
            }
        }
        $classLc = $slot->staticPropertyClassLc ?? $resolved->staticPropertyClassLc;
        if (null !== $classLc && null !== $propName && null !== $frame?->vmContext) {
            $meta = self::staticPropertyTypeMeta($frame->vmContext, $classLc, $propName);
            if (null !== $meta) {
                return $meta;
            }
        }

        return null;
    }

    private static function variableHasDeclaredType(Variable $var): bool
    {
        return null !== $var->typeConstraint
            || null !== $var->dnfArms
            || null !== $var->unionTypeConstraints;
    }

    private static function instancePropertyTypeMeta(ObjectEntry $object, string $propName): ?Variable
    {
        $needle = strtolower($propName);
        foreach ($object->class->properties as $property) {
            if (strtolower($property->name) === $needle) {
                return $property->prototype->resolveIndirect();
            }
        }

        return null;
    }

    private static function staticPropertyTypeMeta(
        \PHPCompiler\VM\Context $ctx,
        string $classLc,
        string $propName,
    ): ?Variable {
        $needle = strtolower($propName);
        if (!isset($ctx->classes[$classLc])) {
            return null;
        }
        $entry = $ctx->classes[$classLc];
        if (!isset($entry->staticProperties[$needle])) {
            return null;
        }

        return $entry->staticProperties[$needle]->resolveIndirect();
    }

    private static function toInteger(Variable $result, Variable $value, ?Frame $frame): void
    {
        try {
            VmScalarType::writeCoercedInt($result, $value, $frame);
        } catch (\LogicException) {
            throw new \LogicException('settype() to integer does not support this value type in this compiler build');
        }
    }

    private static function toFloat(Variable $result, Variable $value, ?Frame $frame): void
    {
        try {
            VmScalarType::writeCoercedFloat($result, $value, $frame);
        } catch (\LogicException) {
            throw new \LogicException('settype() to float does not support this value type in this compiler build');
        }
    }

    private static function toString(Variable $result, Variable $value, ?Frame $frame = null): void
    {
        $v = $value->resolveIndirect();
        EnumCaseSupport::packRejectStringOperand($v);
        if (Variable::TYPE_NULL === $v->type) {
            $result->string('');

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $vm = $frame?->vmContext?->runtime?->vm;
            $ctx = $frame?->vmContext;
            if (null !== $ctx) {
                $ctx->errors->languageWarning(
                    'Array to string conversion',
                    null,
                    0,
                    $ctx,
                    $frame
                );
            }
            $result->string('Array');

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            if (ResourceSupport::isResourceObject($v->toObject())) {
                $vm = $frame?->vmContext?->runtime?->vm;
                $result->string($v->toString($vm, $frame));

                return;
            }
            $vm = $frame?->vmContext?->runtime?->vm;
            if (null === $vm) {
                throw new \Error(
                    'Object of class '.$v->toObject()->class->name.' could not be converted to string'
                );
            }
            $result->string($vm->castObjectToString($v->toObject()));

            return;
        }
        $vm = $frame?->vmContext?->runtime?->vm;
        $result->string($v->toString($vm, $frame));
    }

    /**
     * Zend convert_to_array / php_settype array branch (ext/standard/type.c, #9963).
     */
    private static function toArray(Variable $value, ?Frame $frame = null): HashTable
    {
        $classes = $frame?->vmContext?->classes ?? [];

        return CastSupport::toArray($value, $classes)->toArray();
    }

    /** Zend convert_to_object / php_settype object branch (ext/standard/type.c, #4254, #30793). */
    private static function toObject(Variable $result, Variable $value, ?Frame $frame): void
    {
        $v = $value->resolveIndirect();
        // Resource wrappers are TYPE_OBJECT; Zend still wraps IS_RESOURCE as stdClass.scalar (#30793).
        if (Variable::TYPE_OBJECT === $v->type && !ResourceSupport::isVmResource($v)) {
            $result->copyFrom($v);

            return;
        }

        $ctx = $frame?->vmContext;
        if (null === $ctx || !isset($ctx->classes['stdclass'])) {
            throw new \LogicException('stdClass is not registered');
        }

        $object = new ObjectEntry($ctx->classes['stdclass']);
        $object->constructed = true;

        if (Variable::TYPE_ARRAY === $v->type) {
            foreach ($v->toArray()->iterateKeyed(true) as [$key, $elem]) {
                $keyVar = $key->resolveIndirect();
                $name = Variable::TYPE_INTEGER === $keyVar->type
                    ? (string) $keyVar->toInt()
                    : $keyVar->toString();
                $prop = $object->allocateProperty($name);
                $prop->copyFrom($elem->resolveIndirect());
            }
            $result->object($object);

            return;
        }

        if (Variable::TYPE_NULL === $v->type) {
            $result->object($object);

            return;
        }

        $scalar = $object->allocateProperty('scalar');
        $scalar->copyFrom($v);
        $result->object($object);
    }

}
