<?php

declare(strict_types=1);

namespace PHPCompiler\ext\reflection;

/**
 * php-src @not-serializable Reflection objects (issue #23087):
 * ext/reflection/php_reflection.stub.php — ZEND_ACC_NOT_SERIALIZABLE on core
 * Reflection* types (and subclasses that inherit the flag).
 *
 * Reflection / ReflectionException remain serializable.
 */
final class ReflectionSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        'reflectionfunctionabstract',
        'reflectionfunction',
        'reflectionmethod',
        'reflectiongenerator',
        'reflectionclass',
        'reflectionobject',
        'reflectionproperty',
        'reflectionclassconstant',
        'reflectionparameter',
        'reflectiontype',
        'reflectionnamedtype',
        'reflectionuniontype',
        'reflectionintersectiontype',
        'reflectionextension',
        'reflectionzendextension',
        'reflectionreference',
        'reflectionattribute',
        'reflectionenum',
        'reflectionenumunitcase',
        'reflectionenumbackedcase',
        'reflectionfiber',
        'reflectionconstant',
    ];

    public static function rejectSerialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className): bool
    {
        return \in_array(strtolower(ltrim($className, '\\')), self::DENIED_LC, true);
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
