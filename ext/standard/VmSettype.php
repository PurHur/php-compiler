<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * settype() conversions (ext/standard/type.c php_settype / Zend convert_to_*).
 */
final class VmSettype
{
    public static function apply(Variable $slot, string $typeName): void
    {
        $type = strtolower($typeName);
        $value = $slot->resolveIndirect();
        $result = new Variable();

        switch ($type) {
            case 'integer':
            case 'int':
                self::toInteger($result, $value);
                break;
            case 'double':
            case 'float':
                self::toFloat($result, $value);
                break;
            case 'string':
                self::toString($result, $value);
                break;
            case 'bool':
            case 'boolean':
                $result->bool(boolval::isTruthy($value));
                break;
            case 'array':
                $result->array(self::toArray($value));
                break;
            case 'null':
                $result->null();
                break;
            case 'object':
                throw new \LogicException('settype() to object is not supported in this compiler build');
            case 'resource':
                throw new \ValueError('Cannot convert to resource type');
            default:
                throw new \ValueError('settype(): Argument #2 ($type) must be a valid type');
        }

        $slot->copyFrom($result);
    }

    private static function toInteger(Variable $result, Variable $value): void
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
        throw new \LogicException('settype() to integer does not support this value type in this compiler build');
    }

    private static function toFloat(Variable $result, Variable $value): void
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
