<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/redis surface advertisement — PECL phpredis / redis.c (#6098, #26141).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class RedisExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('redis');
    }

    /** Compliance filenames that exercise redis_* / Redis* / extension_loaded('redis'). */
    public static function isRedisComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'redis_')
            || str_contains($testFileName, '/redis/')
            || str_contains($testFileName, 'extension_loaded_redis');
    }

    /** Phantom-registration guards that assert redis is withheld (#6098 / #26141). */
    public static function isRedisPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'redis_phantom')
            || str_contains($testFileName, 'extension_loaded_redis_phantom')
            || str_contains($testFileName, 'maintainer_gap_redis');
    }

    /**
     * Functional redis cases set {@code PHP_COMPILER_ENABLE_REDIS} / PROFILE via {@code --ENV--};
     * module phantom guards run only when redis is withheld (#26141).
     */
    public static function runsRedisCompliance(string $testFileName): bool
    {
        if (self::isRedisPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}
