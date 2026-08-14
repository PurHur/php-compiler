<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** RedisExtensionPolicy host phpredis gate + forward profile (#6098, #26141). */
final class RedisExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfileWithoutHostRedis(): void
    {
        if (\extension_loaded('redis')) {
            self::markTestSkipped('host has phpredis — phantom withhold not applicable');
        }
        self::assertFalse(CompilerVersion::supportsRedis());
        self::assertFalse(RedisExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueWhenHostHasRedis(): void
    {
        if (!\extension_loaded('redis')) {
            self::markTestSkipped('host lacks phpredis');
        }
        self::assertFalse(CompilerVersion::supportsRedis());
        self::assertTrue(RedisExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsRedis());
            self::assertTrue(RedisExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesExtensionTrueWithExplicitEnable(): void
    {
        if (\extension_loaded('redis') || CompilerVersion::supportsRedis()) {
            self::markTestSkipped('host redis or forward profile already enables');
        }
        $prevEnable = getenv('PHP_COMPILER_ENABLE_REDIS');
        putenv('PHP_COMPILER_ENABLE_REDIS=1');
        try {
            self::assertTrue(RedisExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_REDIS');
            } else {
                putenv('PHP_COMPILER_ENABLE_REDIS='.$prevEnable);
            }
        }
    }
}
