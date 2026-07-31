<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Class constant / enum case names are case-sensitive (unlike global constants / methods).
 *
 * php-src: Zend/zend_constants.c, ZEND_FETCH_CLASS_CONSTANT (#25910);
 * Zend/zend_compile.c declare (#25929) — {@code const A} and {@code const a} are distinct.
 *
 * Storage and lookup keys are the declared / requested casing (VM {@see VM\ClassEntry::$constants},
 * JIT {@see JIT\Builtin\Type\Object_} maps). {@see matchesDeclared()} remains for callers that
 * still carry a separate display-name map.
 */
final class ClassConstName
{
    /**
     * Storage / lookup key for a class constant or enum case name (#25910, #25929).
     *
     * Exact casing — do not lowercase. {@code ::class} is case-insensitive and must be
     * handled by the caller before using this key.
     */
    public static function key(string $name): string
    {
        return $name;
    }

    /**
     * Whether a fetch/request name matches the declared constant casing.
     *
     * {@code ::class} is case-insensitive and must be handled by the caller before lookup.
     * When no declared casing was recorded, accept (legacy / incomplete registration).
     */
    public static function matchesDeclared(string $requested, ?string $declared): bool
    {
        if (null === $declared) {
            return true;
        }

        return $declared === $requested;
    }
}
