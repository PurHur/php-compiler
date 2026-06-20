<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
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
        if (Variable::TYPE_OBJECT === $v->type || Variable::TYPE_ENUM_CASE === $v->type) {
            $objectInt = EnumCaseSupport::tryCastToInt($v, $frame?->vmContext, $frame);
            if (null !== $objectInt) {
                $result->int($objectInt);

                return;
            }
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
        if (Variable::TYPE_OBJECT === $v->type || Variable::TYPE_ENUM_CASE === $v->type) {
            $objectFloat = EnumCaseSupport::tryCastToFloat($v, $frame?->vmContext, $frame);
            if (null !== $objectFloat) {
                $result->float($objectFloat);

                return;
            }
        }
        throw new \LogicException('settype() to float does not support this value type in this compiler build');
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
            $result->string('Array');

            return;
        }
        if (Variable::TYPE_OBJECT === $v->type) {
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

    /** Zend convert_to_object / php_settype object branch (ext/standard/type.c, #4254). */
    private static function toObject(Variable $result, Variable $value, ?Frame $frame): void
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $v->type) {
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
