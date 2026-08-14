<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\CompilerVersion;

/**
 * ext/redis surface advertisement — PECL phpredis / redis.c (#6098, #26141).
 *
 * Pure PHP {@see VmRedis} RESP client stays compiled in-tree. Advertise logical
 * {@code redis} when host Zend loads phpredis ({@code extension_loaded('redis')}),
 * on forward profile ({@see CompilerVersion::supportsRedis()}), or explicit
 * {@code PHP_COMPILER_ENABLE_REDIS=1} — not solely because in-tree VmRedis exists.
 *
 * Reference profile on hosts without phpredis must stay withheld (phantom gate).
 */
final class RedisExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('redis')) {
            return true;
        }

        if (CompilerVersion::supportsRedis()) {
            return true;
        }

        return self::explicitEnableRequested();
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

    /** Explicit side-load / functional-test opt-in when host Zend lacks phpredis (#26141). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_REDIS');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
