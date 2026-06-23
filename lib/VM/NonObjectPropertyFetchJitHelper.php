<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for ZEND_FETCH_PROPERTY_R on non-object receivers (#10268, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — property read on non-object
 * SSOT: {@see ErrorReporter::propertyReadOnNonObject}
 */
final class NonObjectPropertyFetchJitHelper
{
    public static function warningMessage(string $propertyName, string $typeName): string
    {
        return sprintf('Attempt to read property "%s" on %s', $propertyName, $typeName);
    }

    /** Emit Zend E_WARNING; compiled into JIT/AOT via NonObjectPropertyFetchRuntime bridge. */
    public static function emitWarning(string $propertyName, string $typeName): void
    {
        compiler_language_warning(self::warningMessage($propertyName, $typeName));
    }
}
