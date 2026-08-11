<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared object handle + spl_object_hash formatting (issues #3172, #3537, #24292).
 *
 * @see https://github.com/php/php-src/blob/master/ext/spl/php_spl.c PHP_FUNCTION(spl_object_id), php_spl_object_hash()
 * @see https://github.com/php/php-src/blob/master/ext/spl/spl_observer.c spl_object_storage_get_hash()
 */
final class ObjectHandleSupport
{
    public static function requireObjectId(Variable $var, string $function, ?Context $context = null): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return CycleCollector::userVisibleObjectHandle(
                EnumCaseSupport::objectIdForVariable($var, $context)
            );
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($object) must be of type object, %s given',
                $function,
                EnumCaseSupport::typeNameForTypeErrorActual($var)
            ));
        }

        return CycleCollector::userVisibleObjectHandle($var->toObject()->id);
    }

    /** php-src php_spl_object_hash() / SplObjectStorage::getHash(): "%016zx0000000000000000". */
    public static function hashForObjectId(int $objectId): string
    {
        return \sprintf('%016x', $objectId).'0000000000000000';
    }

    public static function hashForObject(Variable $object, string $function, ?Context $context = null): string
    {
        return self::hashForObjectId(self::requireObjectId($object, $function, $context));
    }

    public static function vmTypeName(int $type): string
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
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
