<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\rar\RarEngine;
use PHPCompiler\ext\rar\RarExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\ext\rar\RarEngine */
final class RarEngineTest extends TestCase
{
    public function testBuildAndReadStoreRoundtrip(): void
    {
        $bytes = RarEngine::buildStoreArchive(['a.txt' => "abc\n", 'b/c.txt' => 'xyz']);
        $parsed = RarEngine::readArchiveBytes($bytes);
        self::assertTrue($parsed['ok']);
        self::assertCount(2, $parsed['entries']);
        self::assertSame('a.txt', $parsed['entries'][0]['name']);
        self::assertSame("abc\n", $parsed['entries'][0]['data']);
        self::assertSame('b/c.txt', $parsed['entries'][1]['name']);
        self::assertSame('xyz', $parsed['entries'][1]['data']);
    }

    public function testFixtureTinyRar(): void
    {
        $path = dirname(__DIR__, 2).'/test/fixtures/rar/tiny.rar';
        self::assertFileExists($path);
        $parsed = RarEngine::readArchive($path);
        self::assertTrue($parsed['ok']);
        self::assertSame('hello.txt', $parsed['entries'][0]['name']);
        self::assertSame("hello rar\n", $parsed['entries'][0]['data']);
    }
}

/** @covers \PHPCompiler\ext\rar\RarExtensionPolicy */
final class RarExtensionPolicyTest extends TestCase
{
    public function testExplicitEnable(): void
    {
        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_RAR');
        putenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_ENABLE_RAR');
        try {
            self::assertFalse(RarExtensionPolicy::advertisesExtension());
            putenv('PHP_COMPILER_ENABLE_RAR=1');
            self::assertTrue(RarExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_RAR');
            } else {
                putenv('PHP_COMPILER_ENABLE_RAR='.$prevEnable);
            }
        }
    }
}
