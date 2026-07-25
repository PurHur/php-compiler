<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/**
 * php-src @not-serializable Random\Engine\Secure:
 * - ext/random/random.stub.php (#23102)
 *
 * CSPRNG state must not round-trip. Random\Randomizer wrapping Secure fails
 * when VmSerialize walks the engine property (Zend message names Secure).
 */
final class RandomSecureSerializeDeny
{
    private const SECURE_LC = AdditionalEnginesBuiltin::SECURE_LC;

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
        return self::SECURE_LC === strtolower(ltrim($className, '\\'));
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
