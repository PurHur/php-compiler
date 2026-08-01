<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\Superglobals;

/**
 * class_exists() / is_a() / is_subclass_of() for compiled JIT/AOT modules (#16185, #26406).
 *
 * SSOT: {@see VmReflection::classExists()} / {@see VmReflection::isAString()} / {@see VmReflection::isSubclassOf()}
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(is_a) / is_subclass_of (+ autoload)
 */
final class ClassExistsJitHelper
{
    public static function existsArgv(string $name): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::existsArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::classExists(
            $ctx,
            VmReflection::normalizeGlobalIntrospectionName($name),
            true
        );
    }

    /** is_a($child, $class, true) string subject — autoloads (#26406). */
    public static function isAStringArgv(string $childName, string $className): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::isAStringArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::isAString($ctx, $childName, $className);
    }

    /** is_subclass_of($child, $parent) string subject — autoloads (#26406). */
    public static function isSubclassOfStringArgv(string $childName, string $parentName): bool
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::isSubclassOfStringArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::isSubclassOf($ctx, $childName, $parentName);
    }
}
