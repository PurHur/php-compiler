<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Property name validation (Zend zend_object_handlers.c zend_verify_property_name).
 */
final class PropertyNameSupport
{
    public const LEADING_NULL_BYTE_MESSAGE = 'Cannot access property starting with "\0"';

    public static function hasLeadingNullByte(string $name): bool
    {
        return '' !== $name && "\0" === $name[0];
    }

    public static function leadingNullByteMessage(string $name): ?string
    {
        return self::hasLeadingNullByte($name) ? self::LEADING_NULL_BYTE_MESSAGE : null;
    }
}
