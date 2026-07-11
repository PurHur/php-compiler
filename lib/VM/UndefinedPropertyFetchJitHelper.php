<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for undefined dynamic property reads (#15752, php-in-PHP).
 *
 * php-src: Zend/zend_object_handlers.c — zend_std_read_property when property absent
 * SSOT: {@see ErrorReporter::undefinedPropertyRead}
 */
final class UndefinedPropertyFetchJitHelper
{
    public static function warningMessage(string $className, string $propertyName): string
    {
        return sprintf('Undefined property: %s::$%s', $className, $propertyName);
    }

    /** Emit Zend E_WARNING; compiled into JIT/AOT via UndefinedPropertyFetchRuntime bridge. */
    public static function emitWarning(string $className, string $propertyName): void
    {
        compiler_language_warning(self::warningMessage($className, $propertyName));
    }
}
