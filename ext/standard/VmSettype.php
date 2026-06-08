<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * settype() conversions (ext/standard/type.c php_settype / Zend convert_to_*).
 */
final class VmSettype
{
    public static function apply(Variable $slot, string $typeName, ?Frame $frame = null): void
    {
        $type = strtolower($typeName);
        $value = $slot->resolveIndirect();
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
                self::toString($result, $value);
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
                $enumArray = VmScalarType::enumCaseToSettypeArray($value);
                $result->array(null !== $enumArray ? $enumArray : self::toArray($value));
                break;
            case 'null':
                $result->null();
                break;
            case 'object':
                if (VmScalarType::isEnumCaseVariable($value)) {
                    $result->copyFrom($value);
                    break;
                }
                throw new \LogicException('settype() to object is not supported in this compiler build');
            case 'resource':
                throw new \ValueError('Cannot convert to resource type');
            default:
                throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        $slot->copyFrom($result);
    }

    private static function toInteger(Variable $result, Variable $value, ?Frame $frame): void
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
        $enumInt = VmScalarType::tryEnumCaseToInt($frame, $v);
        if (null !== $enumInt) {
            $result->int($enumInt);

            return;
        }
        throw new \LogicException('settype() to integer does not support this value type in this compiler build');
    }

    private static function toFloat(Variable $result, Variable $value, ?Frame $frame): void
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
        $enumFloat = VmScalarType::tryEnumCaseToFloat($frame, $v);
        if (null !== $enumFloat) {
            $result->float($enumFloat);

            return;
        }
        throw new \LogicException('settype() to float does not support this value type in this compiler build');
    }

    private static function toString(Variable $result, Variable $value): void
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            $result->string('');

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $result->string('Array');

            return;
        }
        $result->string($v->toString());
    }

    private static function toArray(Variable $value): HashTable
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $v->type) {
            return $v->toArray()->replaceCopy();
        }
        if (Variable::TYPE_NULL === $v->type) {
            return new HashTable();
        }
        $ht = new HashTable();
        $elem = new Variable();
        $elem->copyFrom($v);
        $ht->addIndex(0, $elem);

        return $ht;
    }
}
