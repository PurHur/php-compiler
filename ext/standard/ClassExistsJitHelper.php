<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * class_exists() / is_a() / is_subclass_of() for compiled JIT/AOT modules (#16185, #26406, #32706).
 *
 * SSOT: {@see VmReflection::classExists()} / {@see VmReflection::isAString()} / {@see VmReflection::isSubclassOf()}
 * php-src: Zend/zend_builtin_functions.c
 *
 * Helpers return int 0/1 — NestedJIT `: bool` was i1 ABI with `ret i64 0` (#32701/#32706).
 */
final class ClassExistsJitHelper
{
    public static function existsArgv(string $name): int
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
        ) ? 1 : 0;
    }

    /** is_a($child, $class, true) — runtime string subject (#26406, #32706). */
    public static function isAStringArgv(Variable $childName, string $className): int
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::isAStringArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::isAString($ctx, $childName->resolveIndirect()->toString(), $className) ? 1 : 0;
    }

    /** is_subclass_of($child, $parent) — runtime string subject (#26406, #32706). */
    public static function isSubclassOfStringArgv(Variable $childName, string $parentName): int
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassExistsJitHelper::isSubclassOfStringArgv() requires an active VM context in this compiler build'
            );
        }

        return VmReflection::isSubclassOf($ctx, $childName->resolveIndirect()->toString(), $parentName) ? 1 : 0;
    }
}
