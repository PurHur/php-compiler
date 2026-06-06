<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.4 readonly(object) — dynamic object readonly flag (Zend/zend_objects.c, #6485).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects.c zend_mark_object_readonly()
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(readonly)
 */
final class ObjectReadonlySupport
{
    private const TYPE_ERROR = 'readonly(): Argument #1 ($object) must be of type object, %s given';

    public static function isDynamicReadonly(ObjectEntry $object): bool
    {
        return $object->dynamicReadonly;
    }

    /** @throws \TypeError @throws \Error */
    public static function markDynamicReadonly(Variable $arg): void
    {
        $value = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, self::vmTypeName($value->type)));
        }
        $object = $value->toObject();
        if ($object->dynamicReadonly) {
            throw new \Error('Object is already readonly');
        }
        $object->dynamicReadonly = true;
    }

    public static function modifyObjectMessage(ObjectEntry $object): string
    {
        return \sprintf('Cannot modify readonly object of class %s', $object->class->name);
    }

    public static function unsetObjectMessage(ObjectEntry $object): string
    {
        return \sprintf('Cannot unset readonly object of class %s', $object->class->name);
    }

    private static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
