<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Object class names for get_debug_type() (ext/standard/type.c, php_function_get_debug_type).
 *
 * php-src: ZEND_ACC_ANON_CLASS objects return ZSTR_CLASS_ANONYMOUS ("class@anonymous"),
 * not the internal NUL+filename suffix stored in ce->name.
 */
final class VmObjectDebugType
{
    public const ANONYMOUS_LABEL = 'class@anonymous';

    public static function fromClassName(string $className): string
    {
        if (str_contains($className, '@anonymous')) {
            return self::ANONYMOUS_LABEL;
        }

        return $className;
    }
}
