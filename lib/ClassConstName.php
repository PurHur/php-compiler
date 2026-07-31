<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Class constant names are case-sensitive (unlike global constants / methods).
 *
 * php-src: Zend/zend_constants.c, ZEND_FETCH_CLASS_CONSTANT (#25910).
 * Declared casing is stored alongside lowercase keys (VM {@see VM\ClassEntry::$constNames},
 * JIT {@see JIT\Builtin\Type\Object_} display-name map).
 */
final class ClassConstName
{
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
