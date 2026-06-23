<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * Zend scalar casts on enum case operands for intval/floatval/settype (#5623, #5643, ext/standard/type.c).
 */
final class VmScalarType
{
    public static function tryEnumCaseToInt(?Frame $frame, Variable $value): ?int
    {
        return EnumCaseSupport::tryCastToInt($value, $frame?->vmContext, $frame);
    }

    public static function tryEnumCaseToFloat(?Frame $frame, Variable $value): ?float
    {
        return EnumCaseSupport::tryCastToFloat($value, $frame?->vmContext, $frame);
    }

    public static function isEnumCaseVariable(Variable $value): bool
    {
        return null !== EnumCaseSupport::enumClassForCaseVariable($value);
    }

    /**
     * Zend settype($x, 'array') on enum case — ['name' => case, 'value' => backing] (#5643, type.c).
     */
    public static function enumCaseToSettypeArray(Variable $value): ?HashTable
    {
        $entry = EnumCaseSupport::enumCaseEntryForVariable($value);
        if (null === $entry) {
            return null;
        }
        $ht = new HashTable();
        $name = new Variable();
        $name->string($entry->caseName);
        $ht->add('name', $name);
        if (null !== $entry->enumClass->backedType) {
            $backing = new Variable();
            $backing->copyFrom($entry->backingValue);
            $ht->add('value', $backing);
        }

        return $ht;
    }

    /**
     * Zend intval() on array/object/resource operands (#10810, ext/standard/type.c php_intval).
     */
    public static function zendIntvalOperand(Variable $value, ?Frame $frame = null): int
    {
        $result = new Variable();
        self::writeCoercedInt($result, $value, $frame);

        return $result->toInt();
    }

    /**
     * Zend floatval() on array/object/resource operands (#10810, ext/standard/type.c php_floatval).
     */
    public static function zendFloatvalOperand(Variable $value, ?Frame $frame = null): float
    {
        $result = new Variable();
        self::writeCoercedFloat($result, $value, $frame);

        return $result->toFloat();
    }

    /**
     * Zend convert_to_long / settype(..., 'int') (#10690, ext/standard/type.c).
     */
    public static function writeCoercedInt(Variable $result, Variable $value, ?Frame $frame = null): void
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_INTEGER === $v->type) {
            $result->int($v->toInt());

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $result->int((int) $v->toFloat());

            return;
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            $result->int($v->toBool() ? 1 : 0);

            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $result->int((int) $v->toString());

            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $result->int(0);

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $result->int($v->toArray()->getNumElements() > 0 ? 1 : 0);

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type || Variable::TYPE_ENUM_CASE === $v->type) {
            if (Variable::TYPE_OBJECT === $v->type && ResourceSupport::isResourceObject($v->toObject())) {
                $vm = $frame?->vmContext?->runtime?->vm;
                $result->int($v->toInt($vm));

                return;
            }
            $objectInt = EnumCaseSupport::tryCastToInt($v, $frame?->vmContext, $frame);
            if (null !== $objectInt) {
                $result->int($objectInt);

                return;
            }
            if (Variable::TYPE_OBJECT === $v->type) {
                self::legacyPlainObjectScalarCast($result, $v, $frame, 'int');

                return;
            }
        }
        throw new \LogicException('scalar int coercion does not support this value type in this compiler build');
    }

    /**
     * Zend convert_to_double / settype(..., 'float') (#10690, ext/standard/type.c).
     */
    public static function writeCoercedFloat(Variable $result, Variable $value, ?Frame $frame = null): void
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_FLOAT === $v->type) {
            $result->float($v->toFloat());

            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $result->float((float) $v->toInt());

            return;
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            $result->float($v->toBool() ? 1.0 : 0.0);

            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $result->float((float) $v->toString());

            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $result->float(0.0);

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $result->float($v->toArray()->getNumElements() > 0 ? 1.0 : 0.0);

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type || Variable::TYPE_ENUM_CASE === $v->type) {
            if (Variable::TYPE_OBJECT === $v->type && ResourceSupport::isResourceObject($v->toObject())) {
                $handle = ResourceSupport::resolveHandle($v);
                $result->float((float) (null !== $handle ? $handle : 0));

                return;
            }
            $objectFloat = EnumCaseSupport::tryCastToFloat($v, $frame?->vmContext, $frame);
            if (null !== $objectFloat) {
                $result->float($objectFloat);

                return;
            }
            if (Variable::TYPE_OBJECT === $v->type) {
                self::legacyPlainObjectScalarCast($result, $v, $frame, 'float');

                return;
            }
        }
        throw new \LogicException('scalar float coercion does not support this value type in this compiler build');
    }

    /**
     * Zend settype($obj, 'int'|'float') on plain objects — E_WARNING + legacy 1 / 1.0 (#10690, type.c).
     *
     * @param 'int'|'float' $kind
     */
    public static function legacyPlainObjectScalarCast(
        Variable $result,
        Variable $value,
        ?Frame $frame,
        string $kind
    ): void {
        $object = $value->resolveIndirect()->toObject();
        $className = $object->class->name;
        $context = $frame?->vmContext;
        if (null !== $context) {
            $message = 'int' === $kind
                ? "Object of class {$className} could not be converted to int"
                : "Object of class {$className} could not be converted to float";
            $context->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                null !== $frame && '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
                $context,
                $frame
            );
        }
        if ('int' === $kind) {
            $result->int(1);

            return;
        }
        $result->float(1.0);
    }
}
