<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Object class names for get_debug_type() / var_dump / print_r (ext/standard/type.c).
 *
 * php-src stores anonymous class names as `Prefix@anonymous\0file:line$id` (zend_compile.c).
 * Public display strips the NUL provenance and keeps the prefix: parent class if the anon
 * extends one, else the first implemented interface, else `class` (#28840, #17443).
 */
final class VmObjectDebugType
{
    public const ANONYMOUS_LABEL = 'class@anonymous';

    public static function fromClassName(string $className): string
    {
        if (!str_contains($className, '@anonymous')) {
            return $className;
        }
        $nul = strpos($className, "\0");
        if (false !== $nul) {
            return substr($className, 0, $nul);
        }

        return $className;
    }
}
