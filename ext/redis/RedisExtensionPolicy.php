<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\CompilerVersion;

/**
 * ext/redis surface advertisement — PECL phpredis / redis.c (#6098).
 *
 * Pure PHP {@see VmRedis} RESP client stays compiled in-tree but is withheld from
 * extension_loaded() / class_exists('Redis') on the reference profile until
 * {@see CompilerVersion::supportsRedis()} (Zend 8.2 harness typically has no phpredis).
 */
final class RedisExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsRedis();
    }
}
