<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

/**
 * php-src @not-serializable Enchant opaque handles (#23112):
 * - EnchantBroker / EnchantDictionary — ext/enchant/enchant.stub.php
 */
final class EnchantSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmEnchantBroker::CLASS_LC,
        VmEnchantDictionary::CLASS_LC,
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
