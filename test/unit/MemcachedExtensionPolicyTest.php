<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\memcached\MemcachedExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** MemcachedExtensionPolicy phantom withhold on reference profile (#6099). */
final class MemcachedExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(CompilerVersion::supportsMemcached());
            self::assertFalse(MemcachedExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsMemcached());
            self::assertTrue(MemcachedExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
